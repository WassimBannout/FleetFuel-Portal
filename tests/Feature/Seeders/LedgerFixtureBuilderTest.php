<?php

namespace Tests\Feature\Seeders;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Enums\DeliveryStatus;
use App\Enums\RateSource;
use App\Models\AuditLog;
use App\Models\CardMonthlyUsage;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelCardService;
use App\Services\UsageReconciliation;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Support\LedgerFixtureBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\TestCase;

class LedgerFixtureBuilderTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;

    /** @var array{company: Company, otherCompany: Company, station: Station, otherStation: Station, operator: User, otherOperator: User, admin: User, manager: User, diesel: Product, petrol: Product, vehicle: Vehicle, driver: Driver, card: FuelCard} */
    private array $world;

    private LedgerFixtureBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->world = $this->ledgerWorld();
        $this->builder = app(LedgerFixtureBuilder::class);
    }

    public function test_a_purchase_snapshots_its_amounts_and_increments_the_counter_once(): void
    {
        $transaction = $this->purchase('20.00');

        $this->assertSame('1600000.00', $transaction->amount_lbp);
        $this->assertSame('17.88', $transaction->amount_usd);
        $this->assertSame('2026-09-01', $transaction->quota_month->format('Y-m-d'));
        $this->assertSame('60.00', $transaction->tank_capacity_l);
        $this->assertSame(RateSource::Fixture, $transaction->rate_source);

        $this->purchase('10.00', 'POS-TEST-0002');

        $usage = CardMonthlyUsage::query()->sole();
        $this->assertSame('30.00', $usage->used_l);
        $this->assertSame('26.82', $usage->used_usd);
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    public function test_the_exact_remaining_quota_is_accepted_and_the_smallest_excess_refused(): void
    {
        $this->purchase('60.00');
        $this->purchase('40.00', 'POS-TEST-0002');

        $this->assertRefused(fn () => $this->purchase('0.01', 'POS-TEST-0003'), 'quota_exceeded');

        $this->assertSame('100.00', CardMonthlyUsage::query()->sole()->used_l);
        $this->assertSame(2, FuelTransaction::query()->count());
    }

    #[DataProvider('declinedPurchases')]
    public function test_it_refuses_purchases_the_live_service_would_decline(string $case, string $reason): void
    {
        [$card, $station, $operator, $product] = $this->arrange($case);

        $this->assertRefused(
            fn () => $this->builder->recordPurchase($card, $station, $operator, $product, '10.00', CarbonImmutable::now()->subHour(), 'POS-TEST-NO'),
            $reason,
        );

        $this->assertSame(0, FuelTransaction::query()->count());
        $this->assertSame(0, CardMonthlyUsage::query()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function declinedPurchases(): array
    {
        return [
            'blocked card' => ['blocked card', 'card is blocked'],
            'inactive company' => ['inactive company', 'company is inactive'],
            'inactive station' => ['inactive station', 'station is inactive'],
            'operator of another station' => ['other operator', 'not an operator of that station'],
            'product outside the card restriction' => ['restricted product', 'restricted to another product'],
            'petrol into a diesel vehicle' => ['wrong fuel', "vehicle's fuel type"],
        ];
    }

    public function test_reconciliation_reports_counters_that_disagree_with_the_ledger(): void
    {
        $transaction = $this->purchase('20.00');
        $reconciliation = app(UsageReconciliation::class);

        DB::table('card_monthly_usage')->update(['used_l' => '19.99']);
        $this->assertSame([[
            'fuel_card_id' => $transaction->fuel_card_id,
            'month' => '2026-09-01',
            'counter_l' => '19.99',
            'ledger_l' => '20.00',
            'counter_usd' => '17.88',
            'ledger_usd' => '17.88',
        ]], $reconciliation->mismatches());

        // A ledger total with no counter row at all is also reported.
        DB::table('card_monthly_usage')->delete();
        $this->assertSame('0.00', $reconciliation->mismatches()[0]['counter_l']);
    }

    /**
     * The seed once audited limit cuts as "fuel_card.limits_changed" while the
     * screens and the API write "card.limits_changed", so the audit filter
     * listed one change under two names. The builder now goes through
     * FuelCardService, and its row must be the one a live change writes.
     */
    public function test_a_quota_change_writes_the_audit_row_of_a_live_change(): void
    {
        $this->purchase('60.00'); // 53.63 USD used this month
        $at = CarbonImmutable::now()->subMinutes(30);

        $this->builder->changeMonthlyLimits($this->world['card'], $this->world['manager'], ['monthly_limit_usd' => '50.00'], $at);

        $card = $this->world['card']->fresh();
        $this->assertSame('50.00', $card?->monthly_limit_usd);
        $this->assertSame('100.00', $card->monthly_limit_l);
        $this->assertTrue($card->updated_at->equalTo($at));

        $seeded = AuditLog::query()->sole();
        $this->assertSame('card.limits_changed', $seeded->action);
        $this->assertSame('fuel_card', $seeded->auditable_type);
        $this->assertSame(['monthly_limit_l' => '100.00', 'monthly_limit_usd' => '100.00'], $seeded->old_values);
        $this->assertSame(['monthly_limit_l' => '100.00', 'monthly_limit_usd' => '50.00', 'below_current_usage' => true], $seeded->new_values);
        $this->assertSame($this->world['manager']->id, $seeded->user_id);
        $this->assertSame($this->world['company']->id, $seeded->company_id);
        $this->assertTrue($seeded->created_at->equalTo($at));

        app(FuelCardService::class)->updateLimits($card, '100.00', '40.00', $this->world['manager']);

        $live = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertNotSame($seeded->id, $live->id);
        $this->assertSame($seeded->action, $live->action);
        $this->assertSame($seeded->auditable_type, $live->auditable_type);
        $this->assertSame(array_keys($seeded->old_values ?? []), array_keys($live->old_values ?? []));
        $this->assertSame(array_keys($seeded->new_values ?? []), array_keys($live->new_values ?? []));
    }

    public function test_a_historical_quota_change_is_judged_against_its_own_month(): void
    {
        $this->purchase('60.00'); // this month only; last month has no usage

        $this->builder->changeMonthlyLimits($this->world['card'], $this->world['manager'], ['monthly_limit_l' => '50.00'], CarbonImmutable::now()->subMonth());

        $this->assertFalse(AuditLog::query()->sole()->new_values['below_current_usage']);
    }

    public function test_manual_overrides_are_admin_only_and_last_at_most_72_hours(): void
    {
        $start = CarbonImmutable::now();

        $this->assertRefused(fn () => $this->builder->createManualRate($this->world['manager'], '90000.00000000', $start, $start->addHour(), 'Test'), 'Only an admin');
        $this->assertRefused(fn () => $this->builder->createManualRate($this->world['admin'], '90000.00000000', $start, $start->addHours(72)->addSecond(), 'Test'), 'within 72 hours');

        $override = $this->builder->createManualRate($this->world['admin'], '90000.00000000', $start, $start->addHours(72), 'Exactly 72 hours');

        $this->assertSame(RateSource::Manual, $override->source);
        $this->assertSame(1, AuditLog::query()->where('action', 'exchange_rate.override_created')->count());
    }

    public function test_delivery_transitions_follow_the_state_machine_and_roles(): void
    {
        $order = DeliveryOrder::factory()->create(['company_id' => $this->world['company']->id]);
        $otherManager = User::factory()->companyManager($this->world['otherCompany'])->create();
        $now = CarbonImmutable::now();
        $schedule = ['scheduled_start_at' => $now->addDay(), 'scheduled_end_at' => $now->addDay()->addHours(4), 'assigned_truck' => 'TRK-9'];

        $this->assertRefused(fn () => $this->builder->transitionDelivery($order, DeliveryStatus::Delivered, $this->world['admin'], $now), 'cannot move from pending to delivered');
        $this->assertRefused(fn () => $this->builder->transitionDelivery($order, DeliveryStatus::Scheduled, $this->world['manager'], $now, $schedule), 'may not');
        $this->assertRefused(fn () => $this->builder->transitionDelivery($order, DeliveryStatus::Cancelled, $otherManager, $now, ['cancel_reason' => 'Not mine']), 'may not');

        $this->builder->transitionDelivery($order, DeliveryStatus::Cancelled, $this->world['manager'], $now, ['cancel_reason' => 'Plans changed']);

        $this->assertSame(DeliveryStatus::Cancelled, $order->fresh()?->status);
        $this->assertSame(2, DeliveryStatusHistory::query()->where('delivery_order_id', $order->id)->count());
        $this->assertRefused(fn () => $this->builder->transitionDelivery($order, DeliveryStatus::Scheduled, $this->world['admin'], $now, $schedule), 'cannot move from cancelled');
    }

    private function purchase(string $liters, string $externalRef = 'POS-TEST-0001'): FuelTransaction
    {
        return $this->builder->recordPurchase(
            $this->world['card'], $this->world['station'], $this->world['operator'], $this->world['diesel'],
            $liters, CarbonImmutable::now()->subHour(), $externalRef, 45000,
        );
    }

    /**
     * @return array{FuelCard, Station, User, Product}
     */
    private function arrange(string $case): array
    {
        $world = $this->world;
        $purchase = [$world['card'], $world['station'], $world['operator'], $world['diesel']];

        switch ($case) {
            case 'blocked card':
                $world['card']->forceFill(['status' => CardStatus::Blocked])->save();
                break;
            case 'inactive company':
                $world['company']->forceFill(['status' => CompanyStatus::Inactive])->save();
                break;
            case 'inactive station':
                $world['station']->forceFill(['is_active' => false])->save();
                break;
            case 'other operator':
                $purchase[2] = $world['otherOperator'];
                break;
            case 'restricted product':
                $purchase[3] = $world['petrol'];
                break;
            case 'wrong fuel':
                // No product restriction, so only the vehicle's fuel type applies.
                $purchase[0] = FuelCard::factory()->assignedTo($world['vehicle'])->create();
                $purchase[3] = $world['petrol'];
                break;
        }

        return $purchase;
    }

    private function assertRefused(Closure $action, string $reason): void
    {
        try {
            $action();
        } catch (LogicException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());

            return;
        }

        $this->fail("Expected a refusal containing \"{$reason}\".");
    }
}
