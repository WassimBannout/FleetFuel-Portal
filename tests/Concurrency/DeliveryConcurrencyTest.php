<?php

namespace Tests\Concurrency;

use App\Enums\DeliveryStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\User;
use App\Services\DeliveryOrderService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\RunsConcurrentWorkers;
use Tests\Concerns\UsesCommittedDatabase;
use Tests\TestCase;

/**
 * T28: status changes that start from the same expected status, running in
 * separate PHP processes with their own MySQL connections
 * (tests/Concurrency/delivery-worker.php, real HTTP kernel), change the
 * order only once. The loser gets 409 stale_state and nothing else is
 * written. Fixtures and tokens are committed first in the dedicated
 * fleetfuel_test_concurrency database; nothing is mocked.
 *
 * The barrier is the order's row lock: the test holds it, waits until MySQL
 * lists the workers as waiting for it, then releases it. In the ordered
 * cases the test itself makes the first change inside an open transaction
 * and either commits or rolls back.
 */
class DeliveryConcurrencyTest extends TestCase
{
    use RunsConcurrentWorkers;
    use UsesCommittedDatabase;

    private const DATABASE = 'fleetfuel_test_concurrency';

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommittedDatabase(self::DATABASE);
        $this->prepareWorkers();
    }

    protected function tearDown(): void
    {
        $this->stopWorkers();

        parent::tearDown();
    }

    public function test_two_admins_scheduling_the_same_order_at_once_change_it_once(): void
    {
        $world = $this->world();

        $this->whileHoldingOrderLock($world['order'], function () use ($world): void {
            $this->statusWorker($world['adminToken'], $world['order'], $this->schedule('TRK-A'));
            $this->statusWorker($world['secondAdminToken'], $world['order'], $this->schedule('TRK-B'));
            $this->waitUntilWaiting('delivery_orders', 'for update', 2);
        });

        $results = $this->workerResults();
        $this->assertSame([200, 409], $this->statuses($results));
        $winner = $this->withStatus($results, 200)['body']['data'];
        $loser = $this->withStatus($results, 409)['body']['error'];
        $this->assertSame('stale_state', $loser['code']);
        $this->assertSame('scheduled', $loser['details']['current_status']);

        $order = $world['order']->refresh();
        $this->assertSame(DeliveryStatus::Scheduled, $order->status);
        $this->assertSame($winner['assigned_truck'], $order->assigned_truck, 'the stored truck is the winner\'s, never a mix');
        $this->assertTransitions($world['order'], [DeliveryStatus::Scheduled]);
    }

    public function test_an_admin_scheduling_and_a_manager_cancelling_at_once_only_one_wins(): void
    {
        $world = $this->world();

        $this->whileHoldingOrderLock($world['order'], function () use ($world): void {
            $this->statusWorker($world['adminToken'], $world['order'], $this->schedule('TRK-A'));
            $this->statusWorker($world['managerToken'], $world['order'], ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed']);
            $this->waitUntilWaiting('delivery_orders', 'for update', 2);
        });

        $results = $this->workerResults();
        $this->assertSame([200, 409], $this->statuses($results));
        $winner = $this->withStatus($results, 200)['body']['data']['status'];
        $this->assertSame('stale_state', $this->withStatus($results, 409)['body']['error']['code']);

        $this->assertSame($winner, $world['order']->fresh()?->status->value);
        $this->assertTransitions($world['order'], [DeliveryStatus::from($winner)]);
    }

    /** Ordered: a cancellation queued behind a schedule that commits finds the order scheduled. */
    public function test_a_change_waiting_behind_a_committed_change_is_refused_as_stale(): void
    {
        $world = $this->world();

        DB::beginTransaction();
        $this->scheduleInThisProcess($world);
        $this->statusWorker($world['managerToken'], $world['order'], ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed']);
        $this->waitUntilWaiting('delivery_orders', 'for update', 1);
        DB::commit();

        $result = $this->workerResult($this->workers[0]);
        $this->assertSame(409, $result['status']);
        $this->assertSame('stale_state', $result['body']['error']['code']);
        $this->assertSame(DeliveryStatus::Scheduled, $world['order']->fresh()?->status);
        $this->assertTransitions($world['order'], [DeliveryStatus::Scheduled]);
    }

    /** Ordered: if the first change rolls back, the queued one sees pending and succeeds. */
    public function test_a_change_waiting_behind_a_rolled_back_change_succeeds(): void
    {
        $world = $this->world();

        DB::beginTransaction();
        $this->scheduleInThisProcess($world);
        $this->statusWorker($world['managerToken'], $world['order'], ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed']);
        $this->waitUntilWaiting('delivery_orders', 'for update', 1);
        DB::rollBack();

        $result = $this->workerResult($this->workers[0]);
        $this->assertSame(200, $result['status']);
        $this->assertSame('cancelled', $result['body']['data']['status']);

        $order = $world['order']->refresh();
        $this->assertSame(DeliveryStatus::Cancelled, $order->status);
        $this->assertNull($order->assigned_truck, 'nothing from the rolled-back schedule survives');
        $this->assertTransitions($world['order'], [DeliveryStatus::Cancelled]);
    }

    /**
     * @return array{order: DeliveryOrder, admin: User, adminToken: string, secondAdminToken: string, managerToken: string}
     */
    private function world(): array
    {
        $company = Company::factory()->create(['name' => 'Concurrency Test Co']);
        $admin = User::factory()->admin()->create();
        $secondAdmin = User::factory()->admin()->create();
        $manager = User::factory()->companyManager($company)->create();

        return [
            'order' => DeliveryOrder::factory()->create(['company_id' => $company->id, 'created_by' => $manager->id]),
            'admin' => $admin,
            'adminToken' => $admin->createToken('concurrency', $admin->role->tokenAbilities())->plainTextToken,
            'secondAdminToken' => $secondAdmin->createToken('concurrency', $secondAdmin->role->tokenAbilities())->plainTextToken,
            'managerToken' => $manager->createToken('concurrency', $manager->role->tokenAbilities())->plainTextToken,
        ];
    }

    /**
     * @param  Closure(): void  $whileHeld
     */
    private function whileHoldingOrderLock(DeliveryOrder $order, Closure $whileHeld): void
    {
        DB::beginTransaction();
        DB::select('SELECT id FROM delivery_orders WHERE id = ? FOR UPDATE', [$order->id]);
        $whileHeld();
        // Release: every waiting worker now competes at once.
        DB::commit();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function statusWorker(string $token, DeliveryOrder $order, array $body): Process
    {
        return $this->startWorkerProcess('tests/Concurrency/delivery-worker.php', [
            'token' => $token,
            'order_id' => $order->id,
            'body' => $body,
        ], $this->childProcessEnvironment(self::DATABASE));
    }

    /**
     * @param  array{order: DeliveryOrder, admin: User}  $world
     */
    private function scheduleInThisProcess(array $world): void
    {
        app(DeliveryOrderService::class)->transition($world['order'], DeliveryStatus::Pending, DeliveryStatus::Scheduled, [
            'scheduled_start_at' => CarbonImmutable::now()->addDay(),
            'scheduled_end_at' => CarbonImmutable::now()->addDay()->addHours(4),
            'assigned_truck' => 'TRK-LOCAL',
        ], $world['admin']);
    }

    /**
     * A schedule for tomorrow, in Beirut time with its offset.
     *
     * @return array<string, string>
     */
    private function schedule(string $truck): array
    {
        $start = CarbonImmutable::now()->addDay()->startOfHour()->setTimezone('Asia/Beirut');

        return [
            'expected_status' => 'pending',
            'status' => 'scheduled',
            'scheduled_start_at' => $start->format('Y-m-d\TH:i:sP'),
            'scheduled_end_at' => $start->addHours(4)->format('Y-m-d\TH:i:sP'),
            'assigned_truck' => $truck,
        ];
    }

    /**
     * The order's history is the initial pending row plus exactly these
     * moves, with one audit row per move.
     *
     * @param  list<DeliveryStatus>  $moves
     */
    private function assertTransitions(DeliveryOrder $order, array $moves): void
    {
        $this->assertSame(
            [DeliveryStatus::Pending, ...$moves],
            DeliveryStatusHistory::query()->where('delivery_order_id', $order->id)->orderBy('id')->pluck('to_status')->all(),
        );
        $this->assertSame(count($moves), AuditLog::query()->where('action', 'delivery_order.status_changed')->where('auditable_id', $order->id)->count());
    }
}
