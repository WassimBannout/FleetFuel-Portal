<?php

namespace Tests\Concurrency;

use App\Enums\CardStatus;
use App\Enums\ProductCode;
use App\Models\AuditLog;
use App\Models\CardMonthlyUsage;
use App\Models\Company;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelCardService;
use App\Services\FuelTransactionService;
use App\Services\UsageReconciliation;
use App\Support\PosPurchase;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Concerns\SubmitsPosRequests;
use Tests\TestCase;
use Tests\TestDatabaseGuard;
use Throwable;

/**
 * T20–T22 with genuinely overlapping requests. Each request runs in its own
 * PHP process with its own MySQL connection (tests/Concurrency/pos-worker.php)
 * against a dedicated database, fleetfuel_test_concurrency. Fixtures and
 * tokens are committed first; nothing is mocked and nothing runs inside a
 * test-wide transaction.
 *
 * The barrier is the contested lock itself: the test holds it, starts the
 * workers, waits until MySQL lists every worker as waiting for it (SHOW
 * FULL PROCESSLIST), then releases it, so all of them compete at once. For
 * ordered cases (T22, the unique-index race) the test is one side of the
 * race, inside an open transaction.
 */
class PosConcurrencyTest extends TestCase
{
    use SubmitsPosRequests;

    private const DATABASE = 'fleetfuel_test_concurrency';

    /** Seconds to wait for workers to line up before failing, so CI never hangs. */
    private const WAIT_SECONDS = 20;

    private static bool $migrated = false;

    /** @var list<Process> */
    private array $workers = [];

    private string $jobDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useConcurrencyDatabase();
        config(['fleetfuel.exchange_rates.mode' => 'fixture']);

        $this->jobDirectory = storage_path('framework/testing/pos-workers-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->jobDirectory);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        File::deleteDirectory($this->jobDirectory);

        parent::tearDown();
    }

    /** T20: 80 of 100 L used; two stations each ask for 15 L at the same moment. */
    public function test_two_stations_cannot_jointly_overspend_one_card(): void
    {
        $world = $this->world();
        $this->ingest($world['operatorA'], $world['card'], 'SETUP-1', '80.00');

        $this->whileHoldingCardLocks([$world['card']->id], function () use ($world): void {
            $this->purchaseWorker($world['tokenA'], $world['card'], ['external_ref' => 'A-1', 'liters' => '15.00']);
            $this->purchaseWorker($world['tokenB'], $world['card'], ['external_ref' => 'B-1', 'liters' => '15.00']);
            $this->waitUntilWaiting('fuel_cards', 'for update', 2);
        });

        $results = $this->workerResults();
        $this->assertSame([201, 403], $this->statuses($results));
        $this->assertSame('quota_exceeded', $this->withStatus($results, 403)['body']['error']['code']);

        $this->assertUsage($world['card'], '95.00');
        $this->assertSame(2, FuelTransaction::query()->count());
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    /**
     * T20, ordered: a purchase that arrives while another one on the same
     * card is in flight must see that purchase's usage, not the old value
     * (the lost-update race). 80 L used, 15 L in flight, 15 L more asked.
     */
    public function test_a_purchase_behind_an_in_flight_purchase_sees_its_usage(): void
    {
        $world = $this->world();
        $this->ingest($world['operatorA'], $world['card'], 'SETUP-1', '80.00');

        DB::beginTransaction();
        $this->ingest($world['operatorA'], $world['card'], 'FIRST-1', '15.00');
        $worker = $this->purchaseWorker($world['tokenB'], $world['card'], ['external_ref' => 'SECOND-1', 'liters' => '15.00']);
        // Whatever it waits on (the card lock, or the counter row if that lock
        // were missing), a statement stuck for a second is waiting on us.
        $this->waitUntilBlockedFor(1);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame(403, $result['status']);
        $this->assertSame('quota_exceeded', $result['body']['error']['code']);
        $this->assertUsage($world['card'], '95.00');
        $this->assertSame(2, FuelTransaction::query()->count());
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    /** T20: six simultaneous 20 L purchases on a 100 L card: exactly five fit. */
    public function test_simultaneous_purchases_accept_exactly_what_the_quota_allows(): void
    {
        $world = $this->world();

        $this->whileHoldingCardLocks([$world['card']->id], function () use ($world): void {
            foreach (range(1, 6) as $i) {
                $this->purchaseWorker($i % 2 === 0 ? $world['tokenB'] : $world['tokenA'], $world['card'], ['external_ref' => "P-{$i}", 'liters' => '20.00']);
            }
            $this->waitUntilWaiting('fuel_cards', 'for update', 6);
        });

        $this->assertSame([201, 201, 201, 201, 201, 403], $this->statuses($this->workerResults()));
        $this->assertUsage($world['card'], '100.00');
        $this->assertSame(5, FuelTransaction::query()->count());
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    /** T21: the same request sent twice at once creates one purchase and one replay. */
    public function test_identical_simultaneous_retries_create_one_purchase(): void
    {
        $world = $this->world();
        $payload = ['external_ref' => 'SAME-1', 'transacted_at' => $this->fiveMinutesAgo()];

        $this->whileHoldingCardLocks([$world['card']->id], function () use ($world, $payload): void {
            $this->purchaseWorker($world['tokenA'], $world['card'], $payload);
            $this->purchaseWorker($world['tokenA'], $world['card'], $payload);
            $this->waitUntilWaiting('fuel_cards', 'for update', 2);
        });

        $results = $this->workerResults();
        $this->assertSame([200, 201], $this->statuses($results));
        $this->assertSame('true', $this->withStatus($results, 200)['replayed']);
        $this->assertSame($this->withStatus($results, 201)['body'], $this->withStatus($results, 200)['body']);

        $this->assertSame(1, FuelTransaction::query()->count());
        $this->assertUsage($world['card'], '20.00');
    }

    /** T21: one reference, two different purchases at once: one winner and a 409. */
    public function test_conflicting_simultaneous_requests_with_one_reference_produce_one_winner(): void
    {
        $world = $this->world();
        $time = $this->fiveMinutesAgo();

        $this->whileHoldingCardLocks([$world['card']->id], function () use ($world, $time): void {
            $this->purchaseWorker($world['tokenA'], $world['card'], ['external_ref' => 'CLASH-1', 'liters' => '20.00', 'transacted_at' => $time]);
            $this->purchaseWorker($world['tokenA'], $world['card'], ['external_ref' => 'CLASH-1', 'liters' => '25.00', 'transacted_at' => $time]);
            $this->waitUntilWaiting('fuel_cards', 'for update', 2);
        });

        $results = $this->workerResults();
        $this->assertSame([201, 409], $this->statuses($results));
        $this->assertSame('idempotency_conflict', $this->withStatus($results, 409)['body']['error']['code']);

        $winner = FuelTransaction::query()->sole();
        $this->assertSame($this->withStatus($results, 201)['body']['data']['liters'], $winner->liters);
        $this->assertUsage($world['card'], $winner->liters);
    }

    /**
     * T21: one station reference used for two different cards at once. No
     * card lock serializes them; the unique (station_id, external_ref) index
     * decides.
     */
    public function test_one_reference_on_two_cards_at_once_is_settled_by_the_unique_index(): void
    {
        $world = $this->world();
        $second = $this->card($world['company']);
        $time = $this->fiveMinutesAgo();

        $this->whileHoldingCardLocks([$world['card']->id, $second->id], function () use ($world, $second, $time): void {
            $this->purchaseWorker($world['tokenA'], $world['card'], ['external_ref' => 'SHARED-1', 'transacted_at' => $time]);
            $this->purchaseWorker($world['tokenA'], $second, ['external_ref' => 'SHARED-1', 'transacted_at' => $time]);
            $this->waitUntilWaiting('fuel_cards', 'for update', 2);
        });

        $results = $this->workerResults();
        $this->assertSame([201, 409], $this->statuses($results));

        $winner = FuelTransaction::query()->where('external_ref', 'SHARED-1')->sole();
        $loser = $winner->fuel_card_id === $world['card']->id ? $second : $world['card'];
        $this->assertUsage(FuelCard::query()->findOrFail($winner->fuel_card_id), '20.00');
        $this->assertFalse(CardMonthlyUsage::query()->where('fuel_card_id', $loser->id)->where('used_l', '>', 0)->exists(), 'The losing card was charged.');
    }

    /**
     * T21, ordered: the second request's insert waits on the unique index
     * behind the first's uncommitted row, then conflicts when it commits.
     */
    public function test_a_same_reference_insert_waits_on_the_unique_index_and_conflicts_after_commit(): void
    {
        $world = $this->world();
        $second = $this->card($world['company']);

        DB::beginTransaction();
        $this->ingest($world['operatorA'], $world['card'], 'UNIQUE-1');
        $worker = $this->purchaseWorker($world['tokenA'], $second, ['external_ref' => 'UNIQUE-1']);
        $this->waitUntilWaiting('insert into `fuel_transactions`', '', 1);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame(409, $result['status']);
        $this->assertSame('idempotency_conflict', $result['body']['error']['code']);
        $this->assertSame($world['card']->id, FuelTransaction::query()->sole()->fuel_card_id);
        $this->assertFalse(CardMonthlyUsage::query()->where('fuel_card_id', $second->id)->where('used_l', '>', 0)->exists());
    }

    /** The same race, but the first transaction rolls back: the waiting insert succeeds. */
    public function test_a_waiting_same_reference_insert_succeeds_if_the_first_rolls_back(): void
    {
        $world = $this->world();
        $second = $this->card($world['company']);

        DB::beginTransaction();
        $this->ingest($world['operatorA'], $world['card'], 'UNIQUE-2');
        $worker = $this->purchaseWorker($world['tokenA'], $second, ['external_ref' => 'UNIQUE-2']);
        $this->waitUntilWaiting('insert into `fuel_transactions`', '', 1);
        DB::rollBack();

        $this->assertSame(201, $this->workerResult($worker)['status']);
        $this->assertSame($second->id, FuelTransaction::query()->sole()->fuel_card_id);
        $this->assertFalse(CardMonthlyUsage::query()->where('fuel_card_id', $world['card']->id)->exists());
    }

    /** T22, edit first: a block committed while a purchase waits is what that purchase sees. */
    public function test_a_block_that_commits_first_declines_the_waiting_purchase(): void
    {
        $world = $this->world();

        DB::beginTransaction();
        app(FuelCardService::class)->changeStatus($world['card'], CardStatus::Blocked, $world['admin']);
        $worker = $this->purchaseWorker($world['tokenA'], $world['card'], ['external_ref' => 'EDIT-1']);
        $this->waitUntilWaiting('fuel_cards', 'for update', 1);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame(403, $result['status']);
        $this->assertSame('card_blocked', $result['body']['error']['code']);
        $this->assertSame(0, FuelTransaction::query()->count());
    }

    /** T22, edit first: no stale limit is applied after a quota cut wins the lock. */
    public function test_a_quota_cut_that_commits_first_applies_to_the_waiting_purchase(): void
    {
        $world = $this->world();

        DB::beginTransaction();
        app(FuelCardService::class)->updateLimits($world['card'], '10.00', null, $world['admin']);
        $worker = $this->purchaseWorker($world['tokenA'], $world['card'], ['external_ref' => 'EDIT-2', 'liters' => '20.00']);
        $this->waitUntilWaiting('fuel_cards', 'for update', 1);
        DB::commit();

        $result = $this->workerResult($worker);
        $this->assertSame(403, $result['status']);
        $this->assertSame('quota_exceeded', $result['body']['error']['code']);
        $this->assertSame(0, FuelTransaction::query()->count());
    }

    /** T22, purchase first: a quota edit waits for the in-flight purchase and sees its usage. */
    public function test_a_quota_edit_waits_for_an_in_flight_purchase_and_sees_its_usage(): void
    {
        $world = $this->world();

        DB::beginTransaction();
        $this->ingest($world['operatorA'], $world['card'], 'FIRST-1');
        $worker = $this->startWorker([
            'action' => 'set_limits',
            'card_id' => $world['card']->id,
            'actor_id' => $world['admin']->id,
            'limit_l' => '10.00',
            'limit_usd' => null,
        ]);
        $this->waitUntilWaiting('fuel_cards', 'for update', 1);
        DB::commit();

        $this->assertSame('10.00', $this->workerResult($worker)['limit_l']);
        $this->assertUsage($world['card'], '20.00');

        // The edit ran after the purchase committed, so it saw 20 L used.
        $audit = AuditLog::query()->where('action', 'card.limits_changed')->sole();
        $this->assertTrue($audit->new_values['below_current_usage']);
        $this->assertSame('100.00', $audit->old_values['monthly_limit_l']);
    }

    /** T22, purchase first: a block waits for the in-flight purchase, which is kept. */
    public function test_a_block_waits_for_an_in_flight_purchase(): void
    {
        $world = $this->world();

        DB::beginTransaction();
        $this->ingest($world['operatorA'], $world['card'], 'FIRST-2');
        $worker = $this->startWorker(['action' => 'block', 'card_id' => $world['card']->id, 'actor_id' => $world['admin']->id]);
        $this->waitUntilWaiting('fuel_cards', 'for update', 1);
        DB::commit();

        $this->assertSame('blocked', $this->workerResult($worker)['card_status']);
        $this->assertSame(1, FuelTransaction::query()->count());
        $this->assertUsage($world['card'], '20.00');
    }

    // ---------------------------------------------------------------------

    /**
     * Point this test's connection at the dedicated database: created and
     * migrated once, then emptied before each test (its rows are committed).
     */
    private function useConcurrencyDatabase(): void
    {
        DB::statement('CREATE DATABASE IF NOT EXISTS `'.self::DATABASE.'`');
        TestDatabaseGuard::assertSafe('testing', 'mysql', self::DATABASE);

        config(['database.connections.mysql.database' => self::DATABASE]);
        DB::purge('mysql');

        if (! self::$migrated) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            self::$migrated = true;

            return;
        }

        Schema::withoutForeignKeyConstraints(function (): void {
            // Only this database's tables: the MySQL user can see other test databases too.
            foreach (Schema::getTableListing(self::DATABASE, schemaQualified: false) as $table) {
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }
        });
    }

    /**
     * A committed world on the real clock: a company card on a diesel
     * vehicle (100 L, no USD limit), two stations with an operator and a
     * token each, DIESEL at 80000 LBP/L and a fixture rate from two hours ago.
     *
     * @return array{admin: User, company: Company, operatorA: User, tokenA: string, tokenB: string, card: FuelCard}
     */
    private function world(): array
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create();
        $diesel = Product::factory()->forCode(ProductCode::Diesel)->create();

        ProductPrice::factory()->create([
            'product_id' => $diesel->id,
            'price_lbp' => '80000.0000',
            'effective_from' => CarbonImmutable::now()->subDays(10)->startOfSecond(),
            'created_by' => $admin->id,
        ]);
        ExchangeRate::factory()->create(['effective_at' => CarbonImmutable::now()->subHours(2)->startOfSecond()]);

        $operatorA = User::factory()->stationOperator(Station::factory()->create())->create();
        $operatorB = User::factory()->stationOperator(Station::factory()->create())->create();

        return [
            'admin' => $admin,
            'company' => $company,
            'operatorA' => $operatorA,
            'tokenA' => $this->posToken($operatorA),
            'tokenB' => $this->posToken($operatorB),
            'card' => $this->card($company),
        ];
    }

    private function card(Company $company): FuelCard
    {
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id, 'tank_capacity_l' => '500.00']);

        return FuelCard::factory()->assignedTo($vehicle)->limits('100.00', null)->create();
    }

    /** A purchase by this test process itself, through the real service. */
    private function ingest(User $operator, FuelCard $card, string $reference, string $liters = '20.00'): void
    {
        app(FuelTransactionService::class)->ingest($operator, PosPurchase::normalized(
            $reference, $card->card_no, 'DIESEL', $liters, CarbonImmutable::now()->subMinutes(10), null,
        ));
    }

    /**
     * @param  list<int>  $cardIds
     */
    private function whileHoldingCardLocks(array $cardIds, Closure $whileHeld): void
    {
        DB::beginTransaction();

        try {
            DB::select('SELECT id FROM fuel_cards WHERE id IN ('.implode(',', array_fill(0, count($cardIds), '?')).') FOR UPDATE', $cardIds);
            $whileHeld();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // Release: every waiting worker now competes at once.
        DB::commit();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function purchaseWorker(string $token, FuelCard $card, array $overrides): Process
    {
        return $this->startWorker([
            'action' => 'purchase',
            'token' => $token,
            'payload' => $this->posPayload($card->card_no, $overrides + ['transacted_at' => $this->fiveMinutesAgo()]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function startWorker(array $job): Process
    {
        $file = $this->jobDirectory.'/job-'.count($this->workers).'.json';
        file_put_contents($file, json_encode($job, JSON_THROW_ON_ERROR));

        $worker = new Process([PHP_BINARY, base_path('tests/Concurrency/pos-worker.php'), $file], base_path(), [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => base_path('bootstrap/cache/config.testing.php'),
            'DB_DATABASE' => self::DATABASE,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'EXCHANGE_RATE_MODE' => 'fixture',
        ], null, 60);

        $worker->start();

        return $this->workers[] = $worker;
    }

    /**
     * Wait until MySQL shows $count other connections running a statement
     * that contains both fragments, i.e. blocked behind a lock we hold.
     */
    private function waitUntilWaiting(string $fragment, string $alsoContaining, int $count): void
    {
        $ownConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $deadline = microtime(true) + self::WAIT_SECONDS;
        $waiting = 0;

        while (microtime(true) < $deadline) {
            $waiting = collect(DB::select('SHOW FULL PROCESSLIST'))
                ->filter(fn (object $row): bool => (int) $row->Id !== $ownConnection
                    // Laravel uses server-side prepared statements, listed as "Execute".
                    && in_array($row->Command, ['Query', 'Execute'], true)
                    && is_string($row->Info)
                    && str_contains(strtolower($row->Info), strtolower($fragment))
                    && str_contains(strtolower($row->Info), strtolower($alsoContaining)))
                ->count();

            if ($waiting >= $count) {
                return;
            }

            foreach ($this->workers as $worker) {
                if (! $worker->isRunning()) {
                    $this->fail("A worker finished before it reached the lock:\n".$worker->getOutput().$worker->getErrorOutput());
                }
            }

            usleep(20_000);
        }

        $this->fail("Expected {$count} worker(s) waiting on \"{$fragment}\"; saw {$waiting} after ".self::WAIT_SECONDS.' s.');
    }

    /**
     * Wait until $count other connections have been running one statement
     * for at least a second: while this test holds its locks, they are
     * blocked behind them.
     */
    private function waitUntilBlockedFor(int $count): void
    {
        $ownConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (microtime(true) < $deadline) {
            $blocked = collect(DB::select('SHOW FULL PROCESSLIST'))
                ->filter(fn (object $row): bool => (int) $row->Id !== $ownConnection
                    && in_array($row->Command, ['Query', 'Execute'], true)
                    && is_string($row->Info)
                    && (int) $row->Time >= 1)
                ->count();

            if ($blocked >= $count) {
                return;
            }

            usleep(100_000);
        }

        $this->fail("Expected {$count} blocked worker statement(s) within ".self::WAIT_SECONDS.' s.');
    }

    /**
     * @return array<string, mixed>
     */
    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $line = collect(explode("\n", $worker->getOutput()))->first(fn (string $line): bool => str_starts_with($line, 'RESULT:'));

        if (! $worker->isSuccessful() || ! is_string($line)) {
            $this->fail("Worker failed (exit {$worker->getExitCode()}):\n".$worker->getOutput().$worker->getErrorOutput());
        }

        return json_decode(substr($line, 7), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workerResults(): array
    {
        return array_map(fn (Process $worker): array => $this->workerResult($worker), $this->workers);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<int>
     */
    private function statuses(array $results): array
    {
        $statuses = array_map(fn (array $result): int => (int) $result['status'], $results);
        sort($statuses);

        return $statuses;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    private function withStatus(array $results, int $status): array
    {
        return collect($results)->firstOrFail(fn (array $result): bool => $result['status'] === $status);
    }

    private function assertUsage(FuelCard $card, string $liters): void
    {
        $this->assertSame($liters, CardMonthlyUsage::query()->where('fuel_card_id', $card->id)->sole()->used_l);
    }

    private function fiveMinutesAgo(): string
    {
        return CarbonImmutable::now()->subMinutes(5)->startOfSecond()->setTimezone('Asia/Beirut')->format('Y-m-d\TH:i:sP');
    }
}
