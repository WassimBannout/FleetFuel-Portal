<?php

namespace Tests\Feature\Deliveries;

use App\Enums\DeliveryStatus;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Services\DeliveryOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * Delivery screens on real routes with the demo seed (fixture clock
 * 2026-09-28 12:00 Beirut). Covers tenant scope (T04), the actions each role
 * sees, the JSON answers the status buttons use (success, 409 stale, 422),
 * the form fallback without JavaScript, CSRF and escaping.
 */
class DeliveryScreensTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_managers_see_only_their_own_orders_and_operators_are_refused(): void
    {
        $this->actingAs($this->atlasManager())
            ->get('/deliveries')
            ->assertOk()
            ->assertSee('Deliveries')
            ->assertSee('Atlas Bekaa hub, Zahle (demo)')
            ->assertSee('Atlas north yard, Tripoli (demo)')
            ->assertDontSee('Cedar central kitchen');

        $atlasOrder = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $this->actingAs($this->cedarManager());
        $this->get("/deliveries/{$atlasOrder->id}")->assertNotFound();
        $this->get("/deliveries/{$atlasOrder->id}/panel")->assertNotFound();
        $this->patch("/deliveries/{$atlasOrder->id}/status", ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Not mine'])->assertNotFound();

        $this->actingAs($this->operator())->get('/deliveries')->assertForbidden();

        $this->actingAs($this->admin())
            ->get('/deliveries?company='.$this->cedar()->id)
            ->assertOk()
            ->assertSee('Cedar central kitchen')
            ->assertDontSee('Atlas Bekaa hub');
        $this->get('/deliveries?status=pending')
            ->assertOk()
            ->assertSee('Atlas Bekaa hub')
            ->assertDontSee('Atlas north yard');
    }

    public function test_a_manager_requests_a_delivery_in_beirut_time(): void
    {
        $manager = $this->atlasManager();
        $this->actingAs($manager)->get('/deliveries/create')->assertOk()->assertViewIs('deliveries.form')->assertSee('Atlas Logistics');

        $response = $this->post('/deliveries', [
            'address' => 'Atlas workshop, Dora (demo)',
            'governorate' => 'Beirut',
            'liters' => '750',
            'preferred_start_at' => '2026-09-29T08:00',
            'preferred_end_at' => '2026-09-29T12:00',
        ]);

        $order = DeliveryOrder::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('deliveries.show', $order))->assertSessionHas('status');
        $this->assertSame($this->atlas()->id, $order->company_id);
        $this->assertSame('750.00', $order->liters);
        $this->assertSame('2026-09-29 05:00:00', $order->preferred_start_at->utc()->format('Y-m-d H:i:s'), '08:00 Beirut (UTC+3) is stored as 05:00 UTC');
        $this->assertSame(DeliveryStatus::Pending, $order->status);
        $this->assertSame(1, $order->statusHistory()->count());

        // The company is never chosen by a manager, and past windows are refused.
        $this->post('/deliveries', ['company_id' => $this->cedar()->id, 'address' => 'x', 'governorate' => 'Beirut', 'liters' => '1', 'preferred_start_at' => '2026-09-29T08:00', 'preferred_end_at' => '2026-09-29T09:00'])
            ->assertSessionHasErrors('company_id');
        $this->post('/deliveries', ['address' => 'x', 'governorate' => 'Beirut', 'liters' => '1', 'preferred_start_at' => '2026-09-28T11:00', 'preferred_end_at' => '2026-09-28T13:00'])
            ->assertSessionHasErrors('preferred_start_at');
    }

    public function test_an_admin_chooses_the_company_first(): void
    {
        $this->actingAs($this->admin());
        $this->get('/deliveries/create')->assertOk()->assertViewIs('deliveries.choose-company')->assertSee('Cedar Catering');
        $this->get('/deliveries/create?company='.$this->cedar()->id)->assertOk()->assertViewIs('deliveries.form')->assertSee('Cedar Catering');

        $this->post('/deliveries', [
            'company_id' => $this->cedar()->id,
            'address' => 'Cedar central kitchen, Jdeideh (demo)',
            'governorate' => 'Mount Lebanon',
            'liters' => '300.25',
            'preferred_start_at' => '2026-10-02T09:00',
            'preferred_end_at' => '2026-10-02T11:00',
        ])->assertRedirect();

        $order = DeliveryOrder::query()->latest('id')->firstOrFail();
        $this->assertSame($this->cedar()->id, $order->company_id);
        $this->assertSame($this->admin()->id, $order->created_by);
    }

    public function test_the_order_page_shows_the_timeline_and_only_permitted_actions(): void
    {
        $pending = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $scheduled = $this->seededOrder('atlas', DeliveryStatus::Scheduled);
        $delivered = $this->seededOrder('atlas', DeliveryStatus::Delivered);

        $this->actingAs($this->atlasManager())
            ->get("/deliveries/{$pending->id}")
            ->assertOk()
            ->assertSee('Requested by Atlas Fleet Manager')
            ->assertSee('Cancel order')
            ->assertSee('name="expected_status" value="pending"', false)
            ->assertDontSee('Schedule delivery')
            ->assertDontSee('Audit history');
        $this->get("/deliveries/{$scheduled->id}")
            ->assertOk()
            ->assertSee('Distributor staff move the order along')
            ->assertDontSee('Cancel order')
            ->assertDontSee('data-delivery-action', false);

        $this->actingAs($this->admin())
            ->get("/deliveries/{$pending->id}")
            ->assertOk()
            ->assertSee('Schedule delivery')
            ->assertSee('Cancel order')
            ->assertSee('Audit history');
        $this->get("/deliveries/{$delivered->id}")
            ->assertOk()
            ->assertSee('It is final and can no longer change.')
            ->assertSeeInOrder(['Requested by', 'Scheduled by', 'Out for delivery by', 'Delivered by'])
            ->assertSee('delivery_order.status_changed');
    }

    public function test_a_status_button_gets_json_and_the_panel_reloads_with_the_new_state(): void
    {
        $order = $this->seededOrder('atlas', DeliveryStatus::Pending);

        $this->actingAs($this->admin())
            ->patchJson("/deliveries/{$order->id}/status", [
                'expected_status' => 'pending',
                'status' => 'scheduled',
                'scheduled_start_at' => '2026-09-29T09:00',
                'scheduled_end_at' => '2026-09-29T13:00',
                'assigned_truck' => 'TRK-04',
            ])
            ->assertOk()
            ->assertExactJson(['message' => "Order #{$order->id} scheduled.", 'status' => 'scheduled']);

        $this->assertSame('2026-09-29 06:00:00', $order->fresh()?->scheduled_start_at?->utc()->format('Y-m-d H:i:s'));

        $this->get("/deliveries/{$order->id}/panel")
            ->assertOk()
            ->assertViewIs('deliveries._panel')
            ->assertSee('Mark out for delivery')
            ->assertSee('name="expected_status" value="scheduled"', false)
            ->assertSee('TRK-04')
            ->assertDontSee('<html', false);
    }

    public function test_a_stale_button_gets_409_json_and_changes_nothing(): void
    {
        $order = $this->seededOrder('atlas', DeliveryStatus::Scheduled);
        $history = DeliveryStatusHistory::query()->count();

        $this->actingAs($this->admin())
            ->patchJson("/deliveries/{$order->id}/status", ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Customer called'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_state')
            ->assertJsonPath('details.current_status', 'scheduled')
            ->assertJsonPath('message', 'This order changed in the meantime: it is now scheduled. Check its current status before trying again.');

        $this->assertSame(DeliveryStatus::Scheduled, $order->fresh()?->status);
        $this->assertSame($history, DeliveryStatusHistory::query()->count());
    }

    public function test_invalid_details_come_back_as_field_errors(): void
    {
        $order = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $this->actingAs($this->admin());

        $this->patchJson("/deliveries/{$order->id}/status", ['expected_status' => 'pending', 'status' => 'scheduled', 'scheduled_start_at' => '2026-09-29T09:00', 'scheduled_end_at' => '2026-09-29T13:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assigned_truck' => 'Assign a truck.']);

        // Checked by the service: the window must start in the future.
        $this->patchJson("/deliveries/{$order->id}/status", ['expected_status' => 'pending', 'status' => 'scheduled', 'scheduled_start_at' => '2026-09-28T10:00', 'scheduled_end_at' => '2026-09-28T13:00', 'assigned_truck' => 'TRK-04'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_start_at' => 'Scheduled start must be in the future.']);

        $this->assertSame(DeliveryStatus::Pending, $order->fresh()?->status);
    }

    public function test_without_javascript_the_forms_post_and_redirect(): void
    {
        $pending = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $scheduled = $this->seededOrder('atlas', DeliveryStatus::Scheduled);
        $this->actingAs($this->atlasManager());

        $this->patch("/deliveries/{$pending->id}/status", ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed'])
            ->assertRedirect(route('deliveries.show', $pending))
            ->assertSessionHas('status', "Order #{$pending->id} cancelled.");

        $this->from("/deliveries/{$scheduled->id}")
            ->patch("/deliveries/{$scheduled->id}/status", ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed'])
            ->assertRedirect("/deliveries/{$scheduled->id}")
            ->assertSessionHasErrors(['rule' => 'This order changed in the meantime: it is now scheduled. Check its current status before trying again.']);
    }

    public function test_status_changes_need_the_csrf_token(): void
    {
        // Laravel skips the CSRF check in tests; run the real middleware.
        $this->app->bind(PreventRequestForgery::class, fn (Application $app) => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        $order = $this->seededOrder('atlas', DeliveryStatus::Pending);
        $body = ['expected_status' => 'pending', 'status' => 'cancelled', 'reason' => 'Plans changed'];
        $this->actingAs($this->admin())->withSession(['_token' => 'token-in-the-session']);

        $this->patchJson("/deliveries/{$order->id}/status", $body)->assertStatus(419);
        $this->assertSame(DeliveryStatus::Pending, $order->fresh()?->status);

        $this->withHeader('X-CSRF-TOKEN', 'token-in-the-session')
            ->patchJson("/deliveries/{$order->id}/status", $body)
            ->assertOk();
        $this->assertSame(DeliveryStatus::Cancelled, $order->refresh()->status);
    }

    public function test_order_text_is_escaped(): void
    {
        $order = app(DeliveryOrderService::class)->create($this->atlas(), [
            'address' => '<script>alert("x")</script> Depot',
            'governorate' => '<b>Beirut</b>',
            'liters' => '10.00',
            'preferred_start_at' => CarbonImmutable::now()->addDay(),
            'preferred_end_at' => CarbonImmutable::now()->addDay()->addHour(),
        ], $this->atlasManager());

        $this->actingAs($this->atlasManager());
        foreach (["/deliveries/{$order->id}", "/deliveries/{$order->id}/panel", '/deliveries'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertDontSee('<script>alert', false)
                ->assertDontSee('<b>Beirut</b>', false)
                ->assertSee('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; Depot', false);
        }
    }

    private function seededOrder(string $company, DeliveryStatus $status): DeliveryOrder
    {
        return DeliveryOrder::query()
            ->where('company_id', $company === 'atlas' ? $this->atlas()->id : $this->cedar()->id)
            ->where('status', $status)
            ->sole();
    }
}
