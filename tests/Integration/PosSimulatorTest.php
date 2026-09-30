<?php

namespace Tests\Integration;

use App\Models\CardMonthlyUsage;
use App\Models\FuelTransaction;
use App\Services\FuelTransactionService;
use App\Services\UsageReconciliation;
use App\Support\BusinessMonth;
use App\Support\PosPurchase;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\Concerns\SubmitsPosRequests;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

/**
 * T25: the standalone simulator (tools/pos-simulator) against the real
 * application over real HTTP. PHP's built-in web server serves the app on
 * a dedicated test database, seeded with the demo data as of now and given
 * fresh cards by `demo:simulator-cards`; the simulator runs as its own
 * process with its settings in environment variables, exactly as a
 * developer runs it.
 */
class PosSimulatorTest extends TestCase
{
    use BuildsLedgerFixtures;
    use SignsInDemoAccounts;
    use SubmitsPosRequests;
    use UsesCommittedDatabase;

    private const DATABASE = 'fleetfuel_test_integration';

    private ?Process $server = null;

    private string $baseUrl = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertFileExists(
            base_path('tools/pos-simulator/vendor/autoload.php'),
            'Install the simulator first: composer install --working-dir=tools/pos-simulator (make test does this).',
        );

        $this->useCommittedDatabase(self::DATABASE);
        $this->seedDemo(CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->startServer();
    }

    protected function tearDown(): void
    {
        $this->server?->stop(0);

        parent::tearDown();
    }

    public function test_every_scenario_passes_on_fresh_cards_and_the_card_is_charged_exactly_once(): void
    {
        $this->artisan('demo:simulator-cards', ['--tag' => 'E2E'])->assertSuccessful();
        $token = $this->posToken($this->operator());
        $ledgerBefore = FuelTransaction::query()->count();

        $run = $this->simulate('all', ['POS_TOKEN' => $token] + $this->freshCards('E2E'));

        $this->assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
        $output = $run->getOutput();
        foreach (['-> 201 Created', 'Idempotency-Replayed: true', '-> 409 idempotency_conflict', '-> 403 card_blocked', '-> 403 quota_exceeded (liters)', 'Result: 5 scenarios passed'] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        $this->assertStringNotContainsString($token, $output.$run->getErrorOutput());
        $this->assertStringNotContainsString('FF-SIM-E2E-MAIN', $output, 'card numbers are shortened in the output');

        // Exactly one purchase, on the main card, counted once.
        $this->assertSame($ledgerBefore + 1, FuelTransaction::query()->count());
        $purchase = FuelTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame([$this->card('FF-SIM-E2E-MAIN')->id, '20.00'], [$purchase->fuel_card_id, $purchase->liters]);
        $this->assertSame('20.00', $this->usedLiters('FF-SIM-E2E-MAIN'));
        $this->assertSame(0, FuelTransaction::query()->whereIn('fuel_card_id', [$this->card('FF-SIM-E2E-BLOCKED')->id, $this->card('FF-SIM-E2E-TINY')->id])->count());
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());

        // A rerun works while the quota lasts: another single 20 L charge.
        $rerun = $this->simulate('success', ['POS_TOKEN' => $token] + $this->freshCards('E2E'));
        $this->assertSame(0, $rerun->getExitCode(), $rerun->getOutput());
        $this->assertSame('40.00', $this->usedLiters('FF-SIM-E2E-MAIN'));
    }

    public function test_a_consumed_card_fails_with_guidance_and_nothing_is_charged(): void
    {
        $this->artisan('demo:simulator-cards', ['--tag' => 'USED'])->assertSuccessful();
        app(FuelTransactionService::class)->ingest($this->operator(), PosPurchase::normalized(
            'SETUP-90', 'FF-SIM-USED-MAIN', 'DIESEL', '90.00', CarbonImmutable::now()->subMinutes(5), null,
        ));
        $ledger = FuelTransaction::query()->count();

        $run = $this->simulate('success', ['POS_TOKEN' => $this->posToken($this->operator())] + $this->freshCards('USED'));

        $this->assertSame(1, $run->getExitCode(), $run->getOutput());
        $this->assertStringContainsString('has only 10.00 L left this month', $run->getOutput());
        $this->assertStringContainsString('php artisan demo:simulator-cards', $run->getOutput());
        $this->assertStringContainsString('Result: FAILED', $run->getOutput());
        $this->assertSame($ledger, FuelTransaction::query()->count());
        $this->assertSame('90.00', $this->usedLiters('FF-SIM-USED-MAIN'));
    }

    public function test_configuration_comes_only_from_the_environment_and_errors_exit_nonzero(): void
    {
        $missing = $this->simulate('all', []);
        $this->assertSame(2, $missing->getExitCode());
        $this->assertStringContainsString('Set POS_TOKEN, or POS_EMAIL and POS_PASSWORD', $missing->getErrorOutput());

        $this->assertSame(2, $this->simulate('everything', ['POS_TOKEN' => 'x'])->getExitCode());

        $badToken = $this->simulate('blocked', ['POS_TOKEN' => '1|not-a-real-token']);
        $this->assertSame(1, $badToken->getExitCode());
        $this->assertStringContainsString('HTTP 401 unauthenticated', $badToken->getOutput());
    }

    public function test_email_and_password_sign_in_issues_a_token_and_revokes_it_at_the_end(): void
    {
        $credentials = ['POS_EMAIL' => 'operator.beirut@fleetfuel.test', 'POS_PASSWORD' => self::DEMO_PASSWORD];

        $run = $this->simulate('blocked', $credentials);

        $this->assertSame(0, $run->getExitCode(), $run->getOutput());
        $this->assertStringContainsString('Token revoked.', $run->getOutput());
        $this->assertStringNotContainsString(self::DEMO_PASSWORD, $run->getOutput().$run->getErrorOutput());
        $this->assertSame(0, PersonalAccessToken::query()->where('name', 'pos-simulator')->count());

        $refused = $this->simulate('blocked', ['POS_PASSWORD' => 'wrong-password'] + $credentials);
        $this->assertSame(1, $refused->getExitCode());
        $this->assertStringContainsString('The token request was refused: HTTP 401 invalid_credentials', $refused->getOutput());
    }

    /**
     * @param  array<string, string>  $env
     */
    private function simulate(string $scenario, array $env): Process
    {
        $process = new Process([PHP_BINARY, base_path('tools/pos-simulator/bin/pos-simulator'), $scenario], base_path(), $env + [
            'POS_BASE_URL' => $this->baseUrl.'/api/v1',
            // Never inherit simulator settings from the shell running the tests.
            'POS_TOKEN' => false,
            'POS_EMAIL' => false,
            'POS_PASSWORD' => false,
            'POS_CARD' => false,
            'POS_BLOCKED_CARD' => false,
            'POS_TINY_CARD' => false,
            'POS_LITERS' => false,
        ], null, 90);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, string>
     */
    private function freshCards(string $tag): array
    {
        return [
            'POS_CARD' => "FF-SIM-{$tag}-MAIN",
            'POS_BLOCKED_CARD' => "FF-SIM-{$tag}-BLOCKED",
            'POS_TINY_CARD' => "FF-SIM-{$tag}-TINY",
        ];
    }

    private function usedLiters(string $cardNo): ?string
    {
        $used = CardMonthlyUsage::query()
            ->where('fuel_card_id', $this->card($cardNo)->id)
            ->where('month_start', BusinessMonth::for(CarbonImmutable::now()))
            ->value('used_l');

        return $used === null ? null : (string) $used;
    }

    /** `php -S` with Laravel's router script, on a free local port, using the test database. */
    private function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $this->server = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
            public_path(),
            $this->childProcessEnvironment(self::DATABASE),
        );
        $this->server->start();
        $this->baseUrl = "http://127.0.0.1:{$port}";

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if (@file_get_contents($this->baseUrl.'/up', false, stream_context_create(['http' => ['timeout' => 1]])) !== false) {
                return;
            }

            if (! $this->server->isRunning()) {
                break;
            }

            usleep(100_000);
        }

        $this->fail('The PHP test server did not start: '.$this->server->getErrorOutput().$this->server->getOutput());
    }
}
