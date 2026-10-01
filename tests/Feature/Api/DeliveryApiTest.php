<?php

namespace Tests\Feature\Api;

use App\Enums\CompanyStatus;
use App\Enums\DeliveryStatus;
use App\Models\AuditLog;
use App\Models\CardMonthlyUsage;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\FuelTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CallsApi;
use Tests\Concerns\ChecksOpenApiContract;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The delivery API on the demo seed, which has six orders: Atlas has one
 * pending, one scheduled, one out for delivery and one delivered; Cedar
 * one delivered and one cancelled.
 *
 * T26: creation writes the initial history; each accepted change writes
 * exactly one history row and one audit row. T27: skipped, repeated,
 * stale and terminal changes and manager escalation are refused without
 * history. Every response is validated against openapi.json (T24).
 */
class DeliveryApiTest extends TestCase
{
    use BuildsLedgerFixtures;
    use CallsApi;
    use ChecksOpenApiContract;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_a_manager_requests_a_delivery_that_starts_pending_with_one_history_row(): void
    {
        $manager = $this->atlasManager();
        $before = $this->ledgerState();
        $auditRows = AuditLog::query()->count();

        $response = $this->createOrder([
            'address' => 'Atlas workshop, Dora (demo)',
            'governorate' => 'Beirut',
            'liters' => '1500.5',
            'preferred_start_at' => '2026-09-29T12:00:00+03:00',
            'preferred_end_at' => '2026-09-29T16:00:00+03:00',
        ], $this->apiToken($manager))->assertCreated();

        $order = DeliveryOrder::query()->latest('id')->firstOrFail();
        $response->assertHeader('Location', "/api/v1/delivery-orders/{$order->id}");
        $response->assertExactJson(['data' => [
            'id' => $order->id,
            'company_id' => $this->atlas()->id,
            'address' => 'Atlas workshop, Dora (demo)',
            'governorate' => 'Beirut',
            'liters' => '1500.50',
            'preferred_start_at' => '2026-09-29T09:00:00Z',
            'preferred_end_at' => '2026-09-29T13:00:00Z',
            'scheduled_start_at' => null,
            'scheduled_end_at' => null,
            'assigned_truck' => null,
            'status' => 'pending',
            'delivered_at' => null,
            'cancel_reason' => null,
            'history' => [[
                'from_status' => null,
                'to_status' => 'pending',
                'changed_by' => $manager->id,
                'changed_at' => '2026-09-28T09:00:00Z',
                'note' => null,
            ]],
        ]]);

        $this->assertSame($manager->id, $order->created_by);
        $this->assertSame(1, $order->statusHistory()->count());
        $this->assertSame($auditRows, AuditLog::query()->count(), 'creation is recorded by the initial history row, not an audit row');
        $this->assertSame($before, $this->ledgerState(), 'a delivery never touches cards, quotas or the POS ledger');
    }

    public function test_an_admin_takes_an_order_through_the_whole_lifecycle(): void
    {
        $admin = $this->admin();
        $token = $this->apiToken($admin);
        $before = $this->ledgerState();

        $id = $this->createOrder([
            'company_id' => $this->cedar()->id,
            'address' => 'Cedar central kitchen, Jdeideh (demo)',
            'governorate' => 'Mount Lebanon',
            'liters' => '800.00',
            'preferred_start_at' => '2026-09-30T08:00:00+03:00',
            'preferred_end_at' => '2026-09-30T12:00:00+03:00',
        ], $token)->assertCreated()->json('data.id');

        $this->travel(10)->minutes();
        $this->transition($id, [
            'expected_status' => 'pending',
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-09-30T09:00:00+03:00',
            'scheduled_end_at' => '2026-09-30T11:00:00+03:00',
            'assigned_truck' => 'TRK-07',
        ], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.scheduled_start_at', '2026-09-30T06:00:00Z')
            ->assertJsonPath('data.scheduled_end_at', '2026-09-30T08:00:00Z')
            ->assertJsonPath('data.assigned_truck', 'TRK-07');
        $this->assertStep($id, 2, 'pending', 'scheduled', [
            'status' => 'scheduled',
            'scheduled_start_at' => '2026-09-30T06:00:00Z',
            'scheduled_end_at' => '2026-09-30T08:00:00Z',
            'assigned_truck' => 'TRK-07',
        ]);

        // Tokens expire after 24 hours, so each later step signs in again.
        $this->travelTo(CarbonImmutable::parse('2026-09-30T06:05:00Z'));
        $token = $this->apiToken($admin);
        $this->transition($id, ['expected_status' => 'scheduled', 'status' => 'out_for_delivery'], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'out_for_delivery')
            ->assertJsonPath('data.delivered_at', null);
        $this->assertStep($id, 3, 'scheduled', 'out_for_delivery', ['status' => 'out_for_delivery']);

        $this->travelTo(CarbonImmutable::parse('2026-09-30T07:40:00Z'));
        $token = $this->apiToken($admin);
        $this->transition($id, ['expected_status' => 'out_for_delivery', 'status' => 'delivered'], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.delivered_at', '2026-09-30T07:40:00Z');
        $this->assertStep($id, 4, 'out_for_delivery', 'delivered', ['status' => 'delivered', 'delivered_at' => '2026-09-30T07:40:00Z']);

        // The complete timeline, in order, from the detail endpoint.
        $history = $this->assertMatchesOpenApi($this->api('GET', "/api/v1/delivery-orders/{$id}", $this->apiToken($this->cedarManager())), 'get', '/delivery-orders/{id}')
            ->assertOk()
            ->json('data.history');
        $this->assertSame(
            [[null, 'pending', '2026-09-28T09:00:00Z'], ['pending', 'scheduled', '2026-09-28T09:10:00Z'], ['scheduled', 'out_for_delivery', '2026-09-30T06:05:00Z'], ['out_for_delivery', 'delivered', '2026-09-30T07:40:00Z']],
            array_map(fn (array $step): array => [$step['from_status'], $step['to_status'], $step['changed_at']], $history),
        );

        // Delivered is final: nothing reopens it, and nothing is appended.
        $this->transition($id, ['expected_status' => 'delivered', 'status' => 'cancelled', 'reason' => 'Too late'], $token)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'invalid_transition');
        $this->assertSame(4, DeliveryStatusHistory::query()->where('delivery_order_id', $id)->count());
        $this->assertSame(3, $this->auditRowsFor($id));
        $this->assertSame($before, $this->ledgerState());
    }

    public function test_a_manager_may_only_cancel_their_own_pending_order(): void
    {
        $manager = $this->atlasManager();
        $token = $this->apiToken($manager);
        $pending = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $scheduled = $this->seededOrder('atlas', DeliveryStatus::Scheduled);
        $history = DeliveryStatusHistory::query()->count();

        // Escalation: schedule, dispatch, or cancel once scheduled.
        foreach ([
            [$pending, ['expected_status' => 'pending', 'status' => 'scheduled'] + $this->scheduleDetails()],
            [$scheduled, ['expected_status' => 'scheduled', 'status' => 'out_for_delivery']],
            [$scheduled, ['expected_status' => 'scheduled', 'status' => 'cancelled', 'reason' => 'No longer needed']],
        ] as [$order, $body]) {
            $this->transition($order->id, $body, $token)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'forbidden');
        }
        $this->assertSame($history, DeliveryStatusHistory::query()->count());
        $this->assertSame(DeliveryStatus::Scheduled, $scheduled->fresh()?->status);

        // Another company's order does not exist for this manager.
        $cedarOrder = $this->seededOrder('cedar', DeliveryStatus::Delivered);
        $this->assertMatchesOpenApi($this->api('GET', "/api/v1/delivery-orders/{$cedarOrder->id}", $token), 'get', '/delivery-orders/{id}')->assertNotFound();
        $this->transition($cedarOrder->id, ['expected_status' => 'delivered', 'status' => 'cancelled', 'reason' => 'Not mine'], $token)->assertNotFound();

        $this->transition($pending->id, ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => '  Site closed for works  '], $token)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', 'Site closed for works');

        $audit = AuditLog::query()->where('auditable_id', $pending->id)->where('action', 'delivery_order.status_changed')->sole();
        $this->assertSame($manager->id, $audit->user_id);
        $this->assertSame($this->atlas()->id, $audit->company_id);
        $this->assertEquals(['status' => 'pending'], $audit->old_values);
        $this->assertEquals(['status' => 'cancelled', 'cancel_reason' => 'Site closed for works'], $audit->new_values);
        $this->assertSame($history + 1, DeliveryStatusHistory::query()->count());
    }

    public function test_stale_skipped_repeated_and_terminal_changes_are_refused_without_history(): void
    {
        $admin = $this->apiToken($this->admin());
        $pending = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $scheduled = $this->seededOrder('atlas', DeliveryStatus::Scheduled);
        $delivered = $this->seededOrder('atlas', DeliveryStatus::Delivered);
        $cancelled = $this->seededOrder('cedar', DeliveryStatus::Cancelled);
        $snapshot = $this->deliverySnapshot();

        $cases = [
            'stale expected status' => [$pending, $admin, ['expected_status' => 'scheduled', 'status' => 'out_for_delivery'], 'stale_state', 'now pending'],
            'manager cancelling an order that was scheduled meanwhile' => [$scheduled, $this->apiToken($this->atlasManager()), ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed'], 'stale_state', 'now scheduled'],
            'skipped step' => [$pending, $admin, ['expected_status' => 'pending', 'status' => 'delivered'], 'invalid_transition', 'cannot move from pending to delivered'],
            'same status again' => [$scheduled, $admin, ['expected_status' => 'scheduled', 'status' => 'scheduled'] + $this->scheduleDetails(), 'invalid_transition', 'already scheduled'],
            'delivered is final' => [$delivered, $admin, ['expected_status' => 'delivered', 'status' => 'cancelled', 'reason' => 'Mistake'], 'invalid_transition', 'delivered is final'],
            'cancelled is final' => [$cancelled, $admin, ['expected_status' => 'cancelled', 'status' => 'scheduled'] + $this->scheduleDetails(), 'invalid_transition', 'cancelled is final'],
        ];

        foreach ($cases as $case => [$order, $token, $body, $code, $message]) {
            $response = $this->transition($order->id, $body, $token)->assertStatus(409);
            $this->assertSame($code, $response->json('error.code'), $case);
            $this->assertStringContainsString($message, (string) $response->json('error.message'), $case);
            $this->assertSame($order->fresh()?->status->value, $response->json('error.details.current_status'), $case);
        }

        $this->assertSame($snapshot, $this->deliverySnapshot(), 'refused changes leave orders, history and audit untouched');
    }

    public function test_transition_details_are_validated(): void
    {
        $admin = $this->apiToken($this->admin());
        $pending = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $snapshot = $this->deliverySnapshot();
        $schedule = ['expected_status' => 'pending', 'status' => 'scheduled'] + $this->scheduleDetails();

        // Keys are the field expected in error.details; spaces keep them unique.
        $cases = [
            'assigned_truck' => ['assigned_truck' => null] + $schedule,
            'scheduled_start_at' => ['scheduled_start_at' => '2026-09-28T11:00:00+03:00'] + $schedule,
            'scheduled_end_at' => ['scheduled_end_at' => '2026-09-29T07:00:00+03:00'] + $schedule,
            'scheduled_start_at ' => ['scheduled_start_at' => '2027-10-01T08:00:00+03:00', 'scheduled_end_at' => '2027-10-01T12:00:00+03:00'] + $schedule,
            ' scheduled_start_at' => ['scheduled_start_at' => '2026-09-29T08:00:00'] + $schedule,
            'reason' => ['reason' => 'Not needed when scheduling'] + $schedule,
            ' reason' => ['expected_status' => 'pending', 'status' => 'cancelled'],
            ' assigned_truck' => ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed', 'assigned_truck' => 'TRK-01'],
            'note' => ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed', 'note' => 'Extra'],
            'expected_status' => ['status' => 'cancelled', 'reason' => 'Plans changed'],
            'status' => ['expected_status' => 'pending', 'status' => 'shipped'],
        ];

        foreach ($cases as $field => $body) {
            $this->transition($pending->id, array_filter($body, fn ($value) => $value !== null), $admin)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonStructure(['error' => ['details' => [trim($field)]]]);
        }

        $this->assertSame($snapshot, $this->deliverySnapshot());
    }

    public function test_creation_refuses_server_controlled_fields_and_invalid_input(): void
    {
        $manager = $this->apiToken($this->atlasManager());
        $admin = $this->apiToken($this->admin());
        $orders = DeliveryOrder::query()->count();
        $valid = [
            'address' => 'Atlas depot, Port Road, Beirut (demo)',
            'governorate' => 'Beirut',
            'liters' => '500.00',
            'preferred_start_at' => '2026-09-29T08:00:00+03:00',
            'preferred_end_at' => '2026-09-29T12:00:00+03:00',
        ];

        // Keys are the field expected in error.details; spaces keep them unique.
        $cases = [
            'status' => [$manager, ['status' => 'scheduled'] + $valid],
            'assigned_truck' => [$manager, ['assigned_truck' => 'TRK-01'] + $valid],
            'delivered_at' => [$manager, ['delivered_at' => '2026-09-29T09:00:00Z'] + $valid],
            'price_lbp' => [$manager, ['price_lbp' => '80000'] + $valid],
            'company_id' => [$manager, ['company_id' => $this->atlas()->id] + $valid],
            'liters' => [$manager, ['liters' => '0'] + $valid],
            'liters ' => [$manager, ['liters' => '-5'] + $valid],
            ' liters' => [$manager, ['liters' => 500] + $valid],
            'liters  ' => [$manager, ['liters' => '1e3'] + $valid],
            '  liters' => [$manager, ['liters' => '1.234'] + $valid],
            ' liters ' => [$manager, ['liters' => '123456789.00'] + $valid],
            'preferred_start_at' => [$manager, ['preferred_start_at' => '2026-09-28T11:59:59+03:00'] + $valid],
            'preferred_end_at' => [$manager, ['preferred_end_at' => '2026-09-29T08:00:00+03:00'] + $valid],
            ' preferred_start_at' => [$manager, ['preferred_start_at' => '2026-09-29T08:00:00'] + $valid],
            'address' => [$manager, ['address' => str_repeat('a', 501)] + $valid],
            ' company_id' => [$admin, $valid],
            'company_id ' => [$admin, ['company_id' => (string) $this->atlas()->id] + $valid],
        ];

        foreach ($cases as $field => [$token, $body]) {
            $this->createOrder($body, $token)
                ->assertUnprocessable()
                ->assertJsonStructure(['error' => ['details' => [trim($field)]]]);
        }

        // An admin may only choose an active company; a manager of an
        // inactive company cannot request deliveries at all.
        $this->cedar()->forceFill(['status' => CompanyStatus::Inactive])->save();
        $this->createOrder(['company_id' => $this->cedar()->id] + $valid, $admin)
            ->assertUnprocessable()
            ->assertJsonPath('error.details.company_id.0', 'Choose an active company.');
        $this->createOrder($valid, $this->apiToken($this->cedarManager()))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'company_inactive');

        $this->assertSame($orders, DeliveryOrder::query()->count());
    }

    public function test_lists_are_scoped_filtered_and_paginated(): void
    {
        $manager = $this->apiToken($this->atlasManager());
        $admin = $this->apiToken($this->admin());

        $page = $this->list('', $manager)->assertOk();
        $this->assertSame(4, $page->json('meta.total'));
        $this->assertSame(['pending', 'scheduled', 'out_for_delivery', 'delivered'], array_column($page->json('data'), 'status'), 'newest first');
        $this->assertSame([$this->atlas()->id], array_values(array_unique(array_column($page->json('data'), 'company_id'))));
        foreach ($page->json('data') as $order) {
            $this->assertNull($order['history'][0]['from_status']);
            $this->assertSame($order['status'], end($order['history'])['to_status']);
        }

        $this->assertSame(1, $this->list('?status=scheduled', $manager)->assertOk()->json('meta.total'));
        $this->list('?company_id='.$this->atlas()->id, $manager)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['company_id']]]);

        $this->assertSame(6, $this->list('', $admin)->assertOk()->json('meta.total'));
        $cedar = $this->list('?company_id='.$this->cedar()->id, $admin)->assertOk();
        $this->assertSame(['cancelled', 'delivered'], array_column($cedar->json('data'), 'status'));
        $this->assertSame(1, $this->list('?status=cancelled', $admin)->json('meta.total'));
        $second = $this->list('?per_page=2&page=2', $admin)->assertOk();
        $this->assertCount(2, $second->json('data'));
        $this->assertSame(3, $second->json('meta.last_page'));

        $this->list('?status=shipped', $admin)->assertUnprocessable();
        $this->list('?sort=id', $admin)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['sort']]]);
        $this->list('', $this->apiToken($this->operator()))->assertForbidden();
        $this->list('', null)->assertUnauthorized();
    }

    /** The route accepts either delivery ability; the request then requires the one the role needs. */
    public function test_token_abilities_are_checked_for_the_role(): void
    {
        $pending = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $schedule = ['expected_status' => 'pending', 'status' => 'scheduled'] + $this->scheduleDetails();
        $cancel = ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed'];

        $this->transition($pending->id, $schedule, $this->apiToken($this->admin(), ['deliveries:read', 'deliveries:write']))
            ->assertForbidden();
        $this->transition($pending->id, $cancel, $this->apiToken($this->atlasManager(), ['deliveries:status']))
            ->assertForbidden();

        $readOnly = $this->apiToken($this->atlasManager(), ['deliveries:read']);
        $this->list('', $readOnly)->assertOk();
        $this->createOrder(['address' => 'x', 'governorate' => 'Beirut', 'liters' => '1', 'preferred_start_at' => '2026-09-29T08:00:00+03:00', 'preferred_end_at' => '2026-09-29T09:00:00+03:00'], $readOnly)
            ->assertForbidden();
        $this->transition($pending->id, $cancel, $readOnly)->assertForbidden();
        $this->assertSame(DeliveryStatus::Pending, $pending->fresh()?->status);

        $this->transition($pending->id, $schedule, $this->apiToken($this->admin(), ['deliveries:status']))
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function createOrder(array $body, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('POST', '/api/v1/delivery-orders', $token, $body), 'post', '/delivery-orders');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function transition(int $id, array $body, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('PATCH', "/api/v1/delivery-orders/{$id}/status", $token, $body), 'patch', '/delivery-orders/{id}/status');
    }

    /**
     * @return TestResponse<Response>
     */
    private function list(string $query, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('GET', '/api/v1/delivery-orders'.$query, $token), 'get', '/delivery-orders');
    }

    /**
     * A valid schedule for tomorrow (the fixture clock is 2026-09-28 12:00 Beirut).
     *
     * @return array<string, string>
     */
    private function scheduleDetails(): array
    {
        return [
            'scheduled_start_at' => '2026-09-29T08:00:00+03:00',
            'scheduled_end_at' => '2026-09-29T12:00:00+03:00',
            'assigned_truck' => 'TRK-05',
        ];
    }

    private function seededOrder(string $company, DeliveryStatus $status): DeliveryOrder
    {
        return DeliveryOrder::query()
            ->where('company_id', $company === 'atlas' ? $this->atlas()->id : $this->cedar()->id)
            ->where('status', $status)
            ->sole();
    }

    /**
     * One more history row, from -> to, and exactly one matching audit row.
     *
     * @param  array<string, string>  $newValues
     */
    private function assertStep(int $id, int $historyRows, string $from, string $to, array $newValues): void
    {
        $history = DeliveryStatusHistory::query()->where('delivery_order_id', $id)->orderBy('id')->get();
        $this->assertCount($historyRows, $history);
        $this->assertSame([$from, $to], [$history->last()?->from_status?->value, $history->last()?->to_status->value]);
        $this->assertSame($this->admin()->id, $history->last()?->changed_by);

        $audit = AuditLog::query()->where('auditable_id', $id)->where('action', 'delivery_order.status_changed')->orderByDesc('id')->get();
        $this->assertCount($historyRows - 1, $audit, 'one audit row per accepted change');
        $latest = $audit->firstOrFail();
        $this->assertSame($this->admin()->id, $latest->user_id);
        $this->assertSame($this->cedar()->id, $latest->company_id);
        $this->assertEquals(['status' => $from], $latest->old_values);
        $this->assertEquals($newValues, $latest->new_values);
    }

    private function auditRowsFor(int $id): int
    {
        return AuditLog::query()->where('auditable_type', (new DeliveryOrder)->getMorphClass())->where('auditable_id', $id)->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerState(): array
    {
        return [
            'transactions' => FuelTransaction::query()->count(),
            'usage_rows' => CardMonthlyUsage::query()->count(),
            'used_l' => (string) CardMonthlyUsage::query()->sum('used_l'),
            'used_usd' => (string) CardMonthlyUsage::query()->sum('used_usd'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliverySnapshot(): array
    {
        return [
            // Raw rows: plain strings, so assertSame compares values.
            'orders' => DB::table('delivery_orders')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'history' => DeliveryStatusHistory::query()->count(),
            'audit' => AuditLog::query()->count(),
        ];
    }
}
