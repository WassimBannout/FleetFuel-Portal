<?php

namespace Tests\Feature\Deliveries;

use App\Enums\DeliveryStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\User;
use App\Services\DeliveryOrderService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * T26 atomicity: the order, its history row and its audit row are written
 * together or not at all. Each test makes one of the later writes fail and
 * checks that nothing from the change survived.
 */
class DeliveryOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryOrderService $deliveries;

    private User $admin;

    private DeliveryOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-28T09:00:00Z'));
        $this->deliveries = app(DeliveryOrderService::class);
        $this->admin = User::factory()->admin()->create();
        $this->order = DeliveryOrder::factory()->create();
    }

    public function test_a_failing_history_insert_rolls_back_the_status_change_and_writes_no_audit(): void
    {
        DeliveryStatusHistory::creating(fn () => throw new RuntimeException('Forced failure while appending history.'));

        $this->assertFails(fn () => $this->schedule());

        $this->assertUnchanged();
    }

    public function test_a_failing_audit_insert_rolls_back_the_status_change_and_its_history(): void
    {
        AuditLog::creating(fn () => throw new RuntimeException('Forced failure while auditing.'));

        $this->assertFails(fn () => $this->schedule());

        $this->assertUnchanged();
    }

    public function test_a_failing_initial_history_insert_leaves_no_order_behind(): void
    {
        $orders = DeliveryOrder::query()->count();
        DeliveryStatusHistory::creating(fn () => throw new RuntimeException('Forced failure while appending history.'));

        $this->assertFails(fn () => $this->deliveries->create(Company::query()->firstOrFail(), [
            'address' => 'Test depot (demo)',
            'governorate' => 'Beirut',
            'liters' => '100.00',
            'preferred_start_at' => CarbonImmutable::now()->addDay(),
            'preferred_end_at' => CarbonImmutable::now()->addDay()->addHours(2),
        ], $this->admin));

        $this->assertSame($orders, DeliveryOrder::query()->count());
    }

    public function test_the_returned_order_matches_what_was_stored(): void
    {
        $order = $this->schedule();

        $this->assertSame(DeliveryStatus::Scheduled, $order->status);
        $this->assertSame('TRK-09', $order->assigned_truck);
        $this->assertSame('2026-09-29T08:00:00Z', $order->scheduled_start_at?->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame([DeliveryStatus::Pending, DeliveryStatus::Scheduled], $order->statusHistory->pluck('to_status')->all());
        $this->assertEquals($order->getAttributes(), $order->fresh()?->getAttributes());
    }

    private function schedule(): DeliveryOrder
    {
        return $this->deliveries->transition($this->order, DeliveryStatus::Pending, DeliveryStatus::Scheduled, [
            'scheduled_start_at' => CarbonImmutable::parse('2026-09-29T08:00:00Z'),
            'scheduled_end_at' => CarbonImmutable::parse('2026-09-29T12:00:00Z'),
            'assigned_truck' => 'TRK-09',
        ], $this->admin);
    }

    private function assertFails(Closure $change): void
    {
        try {
            $change();
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Forced failure', $e->getMessage());

            return;
        }

        $this->fail('The forced failure did not happen.');
    }

    private function assertUnchanged(): void
    {
        $order = $this->order->refresh();
        $this->assertSame(DeliveryStatus::Pending, $order->status);
        $this->assertNull($order->assigned_truck);
        $this->assertNull($order->scheduled_start_at);
        $this->assertSame(1, DeliveryStatusHistory::query()->where('delivery_order_id', $this->order->id)->count());
        $this->assertSame(0, AuditLog::query()->count());
    }
}
