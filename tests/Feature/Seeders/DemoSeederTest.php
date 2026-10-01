<?php

namespace Tests\Feature\Seeders;

use App\Enums\DeliveryStatus;
use App\Enums\FuelType;
use App\Models\AuditLog;
use App\Models\CardMonthlyUsage;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\UsageReconciliation;
use App\Support\BusinessMonth;
use App\Support\FuelAmounts;
use App\Support\PosRequestHash;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;

    private const EXPECTED_COUNTS = [
        'companies' => 2,
        'stations' => 3,
        'users' => 5,
        'products' => 3,
        'product_prices' => 6,
        'exchange_rates' => 63,
        'vehicles' => 8,
        'drivers' => 8,
        'fuel_cards' => 10,
        'fuel_transactions' => 32,
        'card_monthly_usage' => 12,
        'delivery_orders' => 6,
        'delivery_status_history' => 16,
        'audit_logs' => 12,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
    }

    public function test_it_seeds_the_documented_demo_volumes(): void
    {
        $this->seedDemo();

        $this->assertSame(self::EXPECTED_COUNTS, $this->rowCounts());
    }

    public function test_usage_counters_reconcile_exactly_with_the_ledger(): void
    {
        $this->seedDemo();

        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    public function test_every_ledger_row_carries_consistent_snapshots(): void
    {
        $this->seedDemo();

        $transactions = FuelTransaction::query()
            ->with(['fuelCard.vehicle', 'product', 'productPrice', 'exchangeRate'])
            ->get();

        foreach ($transactions as $transaction) {
            $card = $transaction->fuelCard;
            $label = $transaction->external_ref;

            // Ownership snapshots
            $this->assertSame($card->company_id, $transaction->company_id, $label);
            $this->assertSame($card->vehicle_id, $transaction->vehicle_id, $label);
            $this->assertSame($card->driver_id, $transaction->driver_id, $label);
            $this->assertSame($card->vehicle?->tank_capacity_l, $transaction->tank_capacity_l, $label);

            // Price: the one in force at event time, which is last month's
            // price for last month's purchases.
            $expectedPrices = $transaction->quota_month->format('Y-m-d') === '2026-09-01'
                ? DemoSeeder::CURRENT_PRICES
                : DemoSeeder::PREVIOUS_PRICES;
            $this->assertSame($expectedPrices[$transaction->product->code], $transaction->unit_price_lbp, $label);
            $this->assertSame($transaction->productPrice->price_lbp, $transaction->unit_price_lbp, $label);
            $this->assertTrue($transaction->productPrice->effective_from->lessThanOrEqualTo($transaction->transacted_at), $label);

            // Exchange rate eligible at event time
            $rate = $transaction->exchangeRate;
            $this->assertSame($rate->rate, $transaction->rate_lbp_per_usd, $label);
            $this->assertSame($rate->source, $transaction->rate_source, $label);
            $this->assertTrue($rate->effective_at->lessThanOrEqualTo($transaction->transacted_at), $label);
            $this->assertTrue($rate->expires_at->greaterThan($transaction->transacted_at), $label);

            // Arithmetic, Beirut quota month and idempotency fingerprint
            $this->assertSame(FuelAmounts::amountLbp($transaction->liters, $transaction->unit_price_lbp), $transaction->amount_lbp, $label);
            $this->assertSame(FuelAmounts::amountUsd($transaction->amount_lbp, $transaction->rate_lbp_per_usd), $transaction->amount_usd, $label);
            $this->assertSame(BusinessMonth::for($transaction->transacted_at), $transaction->quota_month->format('Y-m-d'), $label);
            $this->assertSame(PosRequestHash::make(
                $transaction->station_id, $transaction->external_ref, $card->card_no, $transaction->product->code,
                $transaction->liters, $transaction->transacted_at, $transaction->odometer_km,
            ), $transaction->request_hash, $label);
        }
    }

    public function test_simulator_cards_accounts_and_prices_match_the_kit_fixtures(): void
    {
        $this->seedDemo();

        /** @var array{cards: list<array<string, string>>, accounts: list<array<string, string>>, prices: list<array<string, string>>} $fixtures */
        $fixtures = json_decode((string) file_get_contents(base_path('docs/examples/fixtures.json')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($fixtures['cards'] as $expected) {
            $card = FuelCard::query()->with(['company', 'vehicle', 'allowedProduct'])->where('card_no', $expected['card_no'])->firstOrFail();

            $this->assertSame($expected['company'], $card->company->name);
            $this->assertSame($expected['status'], $card->status->value);
            $this->assertSame($expected['monthly_limit_l'], $card->monthly_limit_l);
            $this->assertSame($expected['monthly_limit_usd'], $card->monthly_limit_usd);
            $this->assertSame($expected['fuel_type'], ($card->vehicle->fuel_type ?? $card->allowedProduct?->fuel_type)?->value);
            if (isset($expected['tank_capacity_l'])) {
                $this->assertSame($expected['tank_capacity_l'], $card->vehicle?->tank_capacity_l);
            }

            // Simulator cards start clean: no purchases and no counters.
            $this->assertSame(0, $card->transactions()->count(), $card->card_no);
            $this->assertSame(0, $card->monthlyUsages()->count(), $card->card_no);
        }

        foreach ($fixtures['accounts'] as $expected) {
            $user = User::query()->with(['company', 'station'])->where('email', $expected['email'])->firstOrFail();

            $this->assertSame($expected['role'], $user->role->value);
            $this->assertSame($expected['company'] ?? null, $user->company?->name);
            $this->assertSame($expected['station'] ?? null, $user->station?->name);
            $this->assertTrue(Hash::check(self::DEMO_PASSWORD, $user->password), 'demo password must work');
            $this->assertNotSame(self::DEMO_PASSWORD, $user->password, 'password must be stored hashed');
        }

        foreach ($fixtures['prices'] as $expected) {
            $current = ProductPrice::query()
                ->whereRelation('product', 'code', $expected['product_code'])
                ->where('effective_from', '<=', CarbonImmutable::now())
                ->orderByDesc('effective_from')
                ->firstOrFail();

            $this->assertSame($expected['price_lbp'], $current->price_lbp);
        }
    }

    public function test_the_documented_scenarios_are_present(): void
    {
        $this->seedDemo();
        $asOf = CarbonImmutable::parse(self::FIXTURE_NOW);

        // Tank overfill accepted within quota
        $this->assertTrue(FuelTransaction::query()->whereColumn('liters', '>', 'tank_capacity_l')->exists());

        // Two fills of the same vehicle within 30 minutes
        $fills = FuelTransaction::query()
            ->where('vehicle_id', Vehicle::query()->where('plate_no', 'ATL-102')->value('id'))
            ->orderBy('transacted_at')
            ->pluck('transacted_at');
        $gaps = $fills->sliding(2)->map(fn ($pair) => $pair->last()->getTimestamp() - $pair->first()->getTimestamp());
        $this->assertContains(20 * 60, $gaps->all());

        // Quota reduced below this month's usage, with an audit record
        $card = FuelCard::query()->where('card_no', 'FF-ATLAS-H02')->firstOrFail();
        $usage = CardMonthlyUsage::query()->where('fuel_card_id', $card->id)->where('month_start', '2026-09-01')->firstOrFail();
        $this->assertSame('200.00', $card->monthly_limit_l);
        $this->assertSame('250.00', $usage->used_l);
        $audit = AuditLog::query()->where('action', 'card.limits_changed')->where('auditable_id', $card->id)->firstOrFail();
        $this->assertSame('fuel_card', $audit->auditable_type);
        $this->assertSame(['monthly_limit_l' => '300.00', 'monthly_limit_usd' => '300.00'], $audit->old_values);
        $this->assertSame(['monthly_limit_l' => '200.00', 'monthly_limit_usd' => '300.00', 'below_current_usage' => true], $audit->new_values);
        $this->assertSame($card->company_id, $audit->company_id);

        // FX: an expired provider observation and an audited, already expired
        // manual override that priced exactly one purchase
        $this->assertTrue(ExchangeRate::query()->where('source', 'provider')->where('expires_at', '<=', $asOf)->exists());
        $override = ExchangeRate::query()->where('source', 'manual')->firstOrFail();
        $this->assertTrue($override->expires_at->lessThanOrEqualTo($asOf));
        $this->assertTrue(AuditLog::query()->where('action', 'exchange_rate.override_created')->where('auditable_id', $override->id)->exists());
        $this->assertSame(1, FuelTransaction::query()->where('exchange_rate_id', $override->id)->count());

        // A valid fixture rate throughout the 72 hours before as-of
        foreach ([72 * 3600 - 1, 36 * 3600, 0] as $secondsBefore) {
            $at = $asOf->subSeconds($secondsBefore);
            $this->assertTrue(
                ExchangeRate::query()->where('source', 'fixture')->where('effective_at', '<=', $at)->where('expires_at', '>', $at)->exists(),
                "No fixture rate at {$at->toIso8601ZuluString()}",
            );
        }

        // A petrol vehicle card, and an unrestricted card without vehicle
        $petrol = FuelCard::query()->with('vehicle')->where('card_no', 'FF-ATLAS-PETROL')->firstOrFail();
        $this->assertSame(FuelType::Petrol, $petrol->vehicle?->fuel_type);
        $flex = FuelCard::query()->where('card_no', 'FF-CEDAR-FLEX')->firstOrFail();
        $this->assertNull($flex->vehicle_id);
        $this->assertNull($flex->allowed_product_id);
        $this->assertNull($flex->monthly_limit_l);
        $this->assertNull($flex->monthly_limit_usd);
    }

    public function test_deliveries_cover_every_state_with_a_valid_timeline(): void
    {
        $this->seedDemo();

        $orders = DeliveryOrder::query()->with('statusHistory')->get();
        $this->assertEqualsCanonicalizing(
            ['pending', 'scheduled', 'out_for_delivery', 'delivered', 'delivered', 'cancelled'],
            $orders->map(fn (DeliveryOrder $order): string => $order->status->value)->all(),
        );

        foreach ($orders as $order) {
            $history = $order->statusHistory->values();

            $this->assertNull($history->first()?->from_status);
            $this->assertSame(DeliveryStatus::Pending, $history->first()?->to_status);

            for ($i = 1; $i < $history->count(); $i++) {
                $this->assertSame($history[$i - 1]->to_status, $history[$i]->from_status);
                $this->assertTrue($history[$i]->from_status->canTransitionTo($history[$i]->to_status));
            }

            $this->assertSame($order->status, $history->last()?->to_status);
            $this->assertSame($order->status === DeliveryStatus::Delivered, $order->delivered_at !== null);
        }

        // One audit row per transition after the initial "pending" row.
        $this->assertSame(
            DeliveryStatusHistory::query()->count() - DeliveryOrder::query()->count(),
            AuditLog::query()->where('action', 'delivery_order.status_changed')->count(),
        );
    }

    public function test_seeding_again_changes_nothing(): void
    {
        $this->seedDemo();
        $before = $this->rowCounts();

        $this->artisan('demo:seed', ['--as-of' => self::FIXTURE_NOW])
            ->expectsOutputToContain('nothing changed')
            ->assertSuccessful();
        // The `make setup` path: DatabaseSeeder on a non-empty database.
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $this->rowCounts());
    }

    public function test_the_same_as_of_always_produces_the_same_ledger(): void
    {
        DB::beginTransaction();
        $this->seedDemo();
        $first = $this->ledgerFingerprint();
        DB::rollBack();

        $this->assertSame(0, FuelTransaction::query()->count());

        $this->seedDemo();
        $this->assertSame($first, $this->ledgerFingerprint());
    }

    public function test_an_as_of_early_in_a_month_skips_the_current_month_scenarios(): void
    {
        // 30 minutes into October in Beirut.
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:30:00+03:00'));
        $this->seedDemo('2026-10-01T00:30:00+03:00');

        $this->assertSame(17, FuelTransaction::query()->count());
        $this->assertFalse(FuelTransaction::query()->where('quota_month', '2026-10-01')->exists());
        $this->assertFalse(ExchangeRate::query()->where('source', 'manual')->exists());
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    public function test_it_refuses_to_run_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['fleetfuel.demo.enabled' => true, 'fleetfuel.demo.password' => self::DEMO_PASSWORD]);

        $this->artisan('demo:seed')
            ->expectsOutputToContain('only be seeded in the local or testing environment')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_refuses_without_demo_mode_or_a_demo_password(): void
    {
        config(['fleetfuel.demo.enabled' => true, 'fleetfuel.demo.password' => '']);
        $this->artisan('demo:seed')->expectsOutputToContain('Set DEMO_PASSWORD')->assertFailed();

        config(['fleetfuel.demo.enabled' => false, 'fleetfuel.demo.password' => self::DEMO_PASSWORD]);
        $this->artisan('demo:seed')->expectsOutputToContain('DEMO_MODE is off')->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_database_seeder_adds_only_reference_products_when_demo_mode_is_off(): void
    {
        config(['fleetfuel.demo.enabled' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['DIESEL', 'ULP95', 'ULP98'], Product::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, User::query()->count());
    }

    #[DataProvider('invalidAsOf')]
    public function test_as_of_must_be_a_real_past_instant_with_an_offset(string $asOf): void
    {
        config(['fleetfuel.demo.enabled' => true, 'fleetfuel.demo.password' => self::DEMO_PASSWORD]);

        $this->artisan('demo:seed', ['--as-of' => $asOf])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAsOf(): array
    {
        return [
            'no offset' => ['2026-09-28T09:00:00'],
            'date only' => ['2026-09-28'],
            'impossible date' => ['2026-02-30T09:00:00Z'],
            'in the future' => ['2026-12-01T00:00:00Z'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        return collect(array_keys(self::EXPECTED_COUNTS))
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    /**
     * Everything about the ledger except auto-increment IDs. request_hash is
     * left out because it includes station_id, an auto-increment ID (the
     * snapshot test recomputes every hash instead).
     *
     * @return list<string>
     */
    private function ledgerFingerprint(): array
    {
        return FuelTransaction::query()
            ->with(['fuelCard', 'station'])
            ->orderBy('transacted_at')
            ->orderBy('external_ref')
            ->get()
            ->map(fn (FuelTransaction $t): string => implode('|', [
                $t->external_ref, $t->fuelCard->card_no, $t->station->name, $t->transacted_at->toIso8601ZuluString(),
                $t->liters, $t->amount_lbp, $t->amount_usd, $t->rate_source->value, $t->odometer_km,
            ]))
            ->all();
    }
}
