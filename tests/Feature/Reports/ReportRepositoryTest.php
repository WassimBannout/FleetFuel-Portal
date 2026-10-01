<?php

namespace Tests\Feature\Reports;

use App\Enums\CardStatus;
use App\Enums\ProductCode;
use App\Enums\RateSource;
use App\Models\DeliveryOrder;
use App\Models\ExchangeRate;
use App\Models\FuelTransaction;
use App\Models\ProductPrice;
use App\Models\User;
use App\Repositories\ReportRepository;
use App\Services\FuelCardService;
use App\Support\ReportScope;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\LedgerFixtureBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\Concerns\SubmitsPosRequests;
use Tests\TestCase;

/**
 * ReportRepository on the demo seed (fixture clock 2026-09-28 09:00 UTC,
 * so the current Beirut month is September 2026). Expected figures are
 * worked out from the seed's purchase list by hand, or summed
 * independently from the stored rows; T29–T31 and T33.
 */
class ReportRepositoryTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;
    use SubmitsPosRequests;

    /** September 2026 in Beirut, as a UTC half-open range. */
    private const MONTH_FROM = '2026-08-31T21:00:00Z';

    private const MONTH_TO = '2026-09-30T21:00:00Z';

    private ReportRepository $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
        $this->reports = app(ReportRepository::class);
    }

    /** T29: known totals per company ID; USD is the sum of the stored per-purchase amounts. */
    public function test_consumption_by_company_matches_the_seeded_purchases(): void
    {
        $rows = $this->reports->consumption($this->scope(), 'company');

        $this->assertSame([
            [$this->atlas()->id, 'Atlas Logistics', 9, '515.00', '41675000.00', $this->storedUsd($this->atlas()->id)],
            [$this->cedar()->id, 'Cedar Catering', 6, '325.00', '26775000.00', $this->storedUsd($this->cedar()->id)],
        ], array_map(fn (array $row): array => [$row['group_id'], $row['label'], $row['purchases'], $row['liters'], $row['amount_lbp'], $row['amount_usd']], $rows));
    }

    public function test_consumption_by_vehicle_uses_the_purchase_snapshot_and_groups_card_only_purchases(): void
    {
        // A card's vehicle can differ from what applied at purchase time;
        // the report must follow the vehicle recorded on each purchase.
        $this->card('FF-ATLAS-H02')->forceFill(['vehicle_id' => $this->vehicle('ATL-101')->id])->save();

        $rows = $this->reports->consumption($this->scope(), 'vehicle');

        $this->assertSame([
            [$this->vehicle('ATL-103')->id, 'ATL-103', '250.00'],
            [$this->vehicle('CED-203')->id, 'CED-203', '190.00'],
            [$this->vehicle('ATL-102')->id, 'ATL-102', '170.00'],
            [$this->vehicle('ATL-104')->id, 'ATL-104', '95.00'],
            [$this->vehicle('CED-202')->id, 'CED-202', '75.00'],
            [null, 'No vehicle (card only)', '60.00'],
        ], array_map(fn (array $row): array => [$row['group_id'], $row['label'], $row['liters']], $rows));
    }

    public function test_consumption_by_product_and_equal_names_never_merge(): void
    {
        $this->assertSame(
            [['Diesel (DIESEL)', '635.00'], ['Unleaded 95 (ULP95)', '130.00'], ['Unleaded 98 (ULP98)', '75.00']],
            array_map(fn (array $row): array => [$row['label'], $row['liters']], $this->reports->consumption($this->scope(), 'product')),
        );

        // Two companies with the same name stay two groups, told apart by ID.
        $this->cedar()->forceFill(['name' => 'Atlas Logistics'])->save();
        $rows = $this->reports->consumption($this->scope(), 'company');

        $this->assertSame(['Atlas Logistics', 'Atlas Logistics'], array_column($rows, 'label'));
        $this->assertSame(['515.00', '325.00'], array_column($rows, 'liters'));
        $this->assertNotSame($rows[0]['group_id'], $rows[1]['group_id']);
    }

    /** No recalculation with today's prices or exchange rates. */
    public function test_new_prices_and_rates_never_change_past_totals(): void
    {
        $before = $this->reports->consumption($this->scope(), 'product');

        ProductPrice::query()->forceCreate([
            'product_id' => $this->product(ProductCode::Diesel)->id,
            'price_lbp' => '99000.0000',
            'effective_from' => CarbonImmutable::now(),
            'created_by' => $this->admin()->id,
            'created_at' => CarbonImmutable::now(),
        ]);
        ExchangeRate::query()->forceCreate([
            'base' => 'USD', 'quote' => 'LBP', 'rate' => '50000.00000000', 'source' => RateSource::Manual,
            'effective_at' => CarbonImmutable::now(), 'fetched_at' => null, 'expires_at' => CarbonImmutable::now()->addHours(24),
            'created_by' => $this->admin()->id, 'reason' => 'Report test override', 'created_at' => CarbonImmutable::now(),
        ]);

        $this->assertSame($before, $this->reports->consumption($this->scope(), 'product'));
    }

    public function test_a_manager_is_pinned_to_their_own_company(): void
    {
        $scope = $this->scope($this->atlasManager(), company: $this->cedar()->id);

        $this->assertSame([$this->atlas()->id], array_column($this->reports->consumption($scope, 'company'), 'group_id'));
        $this->assertSame(['ATL-103', 'ATL-102', 'ATL-104'], array_column($this->reports->consumption($scope, 'vehicle'), 'label'));
        $this->assertNotContains('FF-CEDAR-H01', array_column($this->reports->quotaExceptions($scope->companyId, '2026-09-01'), 'card_no'));
        $this->assertSame(['Beirut'], array_column($this->reports->deliverySla($scope->companyId, $scope->from, $scope->to), 'governorate'));
    }

    public function test_ranges_are_half_open(): void
    {
        $purchase = FuelTransaction::query()->where('company_id', $this->cedar()->id)
            ->where('transacted_at', '>=', CarbonImmutable::parse(self::MONTH_FROM))
            ->orderBy('transacted_at')->orderBy('id')->firstOrFail();
        $at = $purchase->transacted_at->utc()->format('Y-m-d\TH:i:s\Z');

        $endingThere = $this->reports->consumption($this->scope(company: $this->cedar()->id, to: $at), 'company');
        $startingThere = $this->reports->consumption($this->scope(company: $this->cedar()->id, from: $at), 'company');

        $this->assertSame([], $endingThere, 'a purchase exactly at the end is outside the range');
        $this->assertSame('325.00', $startingThere[0]['liters'], 'a purchase exactly at the start is inside it');
    }

    /** Equal liters are ordered by station ID; the limit is applied in SQL. */
    public function test_top_stations_order_is_stable(): void
    {
        $this->assertSame(
            [['North Demo Station', '425.00'], ['Harbor Demo Station', '415.00']],
            array_map(fn (array $row): array => [$row['station'], $row['liters']], $this->reports->topStations($this->scope())),
        );

        // 10 L more at Harbor makes a tie: the lower station ID comes first.
        $this->purchase('FF-ATLAS-H01', 'harbor', '10.00', '2026-09-27T06:00:00Z', 122960);
        $tied = $this->reports->topStations($this->scope());

        $this->assertSame(['425.00', '425.00'], array_column($tied, 'liters'));
        $this->assertLessThan($tied[1]['station_id'], $tied[0]['station_id']);
        $this->assertCount(1, $this->reports->topStations($this->scope(), 1));
    }

    /** T30: a reduced limit, a limit reached exactly, a blocked card; declined purchases add nothing. */
    public function test_quota_exceptions_use_this_months_counter_and_todays_limits(): void
    {
        $exceptions = $this->reports->quotaExceptions(null, '2026-09-01');
        $this->assertSame(['FF-ATLAS-BLOCKED', 'FF-ATLAS-H02'], array_column($exceptions, 'card_no'));
        $this->assertSame(['Blocked: every purchase is declined.'], $exceptions[0]['reasons']);
        $this->assertSame(["Over the liter limit: 250.00 L used of 200.00 L (the limit was lowered below this month's usage)."], $exceptions[1]['reasons']);

        // Exactly at the limit: zero left counts as an exception.
        app(FuelCardService::class)->updateLimits($this->card('FF-ATLAS-H01'), '170.00', '400.00', $this->admin());
        // An archived card is no longer listed, even if it was blocked.
        app(FuelCardService::class)->changeStatus($this->card('FF-ATLAS-BLOCKED'), CardStatus::Archived, $this->admin());

        $exceptions = $this->reports->quotaExceptions(null, '2026-09-01');
        $this->assertSame(['FF-ATLAS-H01', 'FF-ATLAS-H02'], array_column($exceptions, 'card_no'));
        $this->assertSame(['Liter limit reached: 170.00 of 170.00 L used, nothing left this month.'], $exceptions[0]['reasons']);

        // A declined purchase is not consumption and changes no counter.
        $consumption = $this->reports->consumption($this->scope(), 'company');
        $this->submitPurchase($this->posToken($this->operator()), $this->posPayload('FF-ATLAS-TINY', ['liters' => '6.00']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'quota_exceeded');

        $this->assertSame($consumption, $this->reports->consumption($this->scope(), 'company'));
        $this->assertSame($exceptions, $this->reports->quotaExceptions(null, '2026-09-01'));

        // Counters are monthly: nothing is used yet in October.
        $this->assertSame([], $this->reports->quotaExceptions(null, '2026-10-01'));
    }

    public function test_tank_overfills_use_the_capacity_recorded_at_purchase_time(): void
    {
        $this->vehicle('ATL-102')->forceFill(['tank_capacity_l' => '500.00'])->save();

        $overfills = $this->reports->tankOverfills($this->scope());

        $this->assertSame([['ATL-102', '75.00', '60.00']], array_map(fn (array $row): array => [$row['vehicle_plate'], $row['liters'], $row['tank_capacity_l']], $overfills));
    }

    /** T31: the seeded 20-minute refill, the 30-minute boundary, same-second fills, and card-only purchases. */
    public function test_rapid_fills_follow_the_30_minute_boundary_and_stable_ties(): void
    {
        $seeded = $this->reports->rapidFills($this->scope());
        $this->assertSame([['ATL-102', 1200]], array_map(fn (array $row): array => [$row['vehicle_plate'], $row['seconds_since_previous']], $seeded));

        $a = $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T08:00:00Z', 152500);
        $b = $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T08:30:00Z', 152530);   // exactly 30 min: not flagged
        $c = $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T08:59:59Z', 152560);   // 29 min 59 s: flagged
        $d = $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T11:00:00Z', 152600);
        $e = $this->purchase('FF-CEDAR-H02', 'north', '10.00', '2026-09-27T11:00:00Z', 152600);    // same second, later ID: flagged
        $this->purchase('FF-CEDAR-FLEX', 'harbor', '10.00', '2026-09-27T12:00:00Z', null);
        $this->purchase('FF-CEDAR-FLEX', 'harbor', '10.00', '2026-09-27T12:01:00Z', null);        // no vehicle: never flagged
        $this->assertGreaterThan($d->id, $e->id);

        $flagged = $this->reports->rapidFills($this->scope());
        $this->assertSame(
            [[$seeded[0]['id'], 1200, $seeded[0]['previous_id']], [$c->id, 1799, $b->id], [$e->id, 0, $d->id]],
            array_map(fn (array $row): array => [$row['id'], $row['seconds_since_previous'], $row['previous_id']], $flagged),
        );

        // The predecessor of the first fill in range is read even though it
        // is before the range start.
        $fromC = $this->reports->rapidFills($this->scope(from: '2026-09-27T08:59:59Z'));
        $this->assertSame([$c->id, $e->id], array_column($fromC, 'id'));
        $this->assertSame($b->id, $fromC[0]['previous_id']);
        $this->assertNotContains($a->id, array_column($fromC, 'id'));
    }

    public function test_efficiency_is_an_estimate_from_recorded_readings_only(): void
    {
        $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T08:00:00Z', 152500);
        $missing = $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T10:00:00Z', null);
        $after = $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T12:00:00Z', 152700);
        $lower = $this->purchase('FF-CEDAR-H02', 'harbor', '10.00', '2026-09-27T14:00:00Z', 152650);
        // The vehicle's current odometer is never used.
        $this->vehicle('CED-203')->forceFill(['odometer_km' => 999999])->save();

        $rows = collect($this->reports->efficiency($this->scope()))->keyBy('id');

        // First September fill of ATL-102: 600 km since the August fill, 40 L.
        $first = $rows->first(fn (array $row): bool => $row['vehicle_plate'] === 'ATL-102');
        $this->assertSame([122400, 121800, 600, '15.00'], [$first['odometer_km'], $first['previous_odometer_km'], $first['distance_km'], $first['km_per_liter']]);

        $this->assertSame([null, 'Odometer reading missing.'], [$rows[$missing->id]['km_per_liter'], $rows[$missing->id]['note']]);
        $this->assertSame([null, 'Odometer reading missing.'], [$rows[$after->id]['km_per_liter'], $rows[$after->id]['note']]);
        $this->assertSame([null, 'Odometer not higher than at the previous fill.'], [$rows[$lower->id]['km_per_liter'], $rows[$lower->id]['note']]);

        // Card-only purchases have no vehicle, so nothing to estimate.
        $cardOnly = FuelTransaction::query()->whereNull('vehicle_id')->pluck('id')->all();
        $this->assertNotSame([], $cardOnly);
        $this->assertSame([], array_values(array_intersect($cardOnly, $rows->keys()->all())));
    }

    /** T33: request-to-delivery hours from the history rows, delivered orders only. */
    public function test_delivery_sla_uses_history_boundaries_and_only_delivered_orders(): void
    {
        $from = CarbonImmutable::parse(self::MONTH_FROM);
        $to = CarbonImmutable::parse(self::MONTH_TO);

        $expected = [
            ['governorate' => 'Beirut', 'delivered' => 1, 'average_hours' => '74.00', 'fastest_hours' => '74.00', 'slowest_hours' => '74.00', 'total_seconds' => 266400],
            ['governorate' => 'Mount Lebanon', 'delivered' => 1, 'average_hours' => '51.00', 'fastest_hours' => '51.00', 'slowest_hours' => '51.00', 'total_seconds' => 183600],
        ];
        $this->assertSame($expected, $this->reports->deliverySla(null, $from, $to));

        // The order columns are not the source: only the timeline counts.
        DeliveryOrder::query()->update(['delivered_at' => '2026-09-01 00:00:00', 'created_at' => '2026-09-01 00:00:00']);
        $this->assertSame($expected, app(ReportRepository::class)->deliverySla(null, $from, $to), 'computed again after the change');

        // Filtered by delivery time: before order 1 was delivered, nothing.
        $this->assertSame([], $this->reports->deliverySla(null, $from, CarbonImmutable::parse('2026-09-19T10:59:59Z')));
    }

    private function scope(?User $user = null, ?int $company = null, string $from = self::MONTH_FROM, string $to = self::MONTH_TO): ReportScope
    {
        return ReportScope::for($user ?? $this->admin(), CarbonImmutable::parse($from), CarbonImmutable::parse($to), $company);
    }

    /** The company's September USD total, summed in PHP from the stored rows. */
    private function storedUsd(int $companyId): string
    {
        return (string) FuelTransaction::query()
            ->where('company_id', $companyId)
            ->where('transacted_at', '>=', CarbonImmutable::parse(self::MONTH_FROM))
            ->where('transacted_at', '<', CarbonImmutable::parse(self::MONTH_TO))
            ->pluck('amount_usd')
            ->reduce(fn (BigDecimal $total, string $usd): BigDecimal => $total->plus($usd), BigDecimal::zero())
            ->toScale(2);
    }

    private function purchase(string $card, string $station, string $liters, string $at, ?int $odometer): FuelTransaction
    {
        [$stationName, $operator] = $station === 'harbor'
            ? ['Harbor Demo Station', 'operator.beirut@fleetfuel.test']
            : ['North Demo Station', 'operator.tripoli@fleetfuel.test'];

        return app(LedgerFixtureBuilder::class)->recordPurchase(
            $this->card($card), $this->station($stationName), $this->account($operator), $this->product(ProductCode::Diesel),
            $liters, CarbonImmutable::parse($at), 'RPT-'.substr(md5($card.$at.$station), 0, 12), $odometer,
        );
    }
}
