<?php

namespace Tests\Feature\Pos;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Enums\ProductCode;
use App\Models\CardMonthlyUsage;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelCardService;
use App\Services\UsageReconciliation;
use App\Support\PosRequestHash;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SubmitsPosRequests;
use Tests\TestCase;

/**
 * POST /api/v1/transactions on MySQL through the real HTTP stack: T06 for
 * this route, T10/T12 (ledger part), T13–T19 and T23 (rollback). The
 * overlapping-worker cases T20–T22 are in tests/Concurrency.
 *
 * World (BuildsLedgerFixtures::ledgerWorld): a diesel card on a 60 L diesel
 * vehicle with a driver, 100 L / 100 USD limits, DIESEL at 80000 LBP/L and
 * a fixture rate of 89500 from today 00:00 UTC. The clock is frozen at
 * 2026-09-28T09:00:00Z; the default purchase is 20 L one hour earlier.
 */
class PosIngestionTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SubmitsPosRequests;

    /** @var array<string, mixed> */
    private array $world;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        config(['fleetfuel.exchange_rates.mode' => 'fixture']);

        $this->world = $this->ledgerWorld();
        $this->token = $this->posToken($this->world['operator']);
    }

    /** T13 */
    public function test_a_new_purchase_is_recorded_with_its_snapshots_and_counted_once(): void
    {
        $card = $this->card();
        $response = $this->submitPurchase($this->token, $this->payload())->assertCreated();

        $transaction = FuelTransaction::query()->sole();
        $response->assertHeader('Location', '/api/v1/transactions/'.$transaction->id)
            ->assertHeaderMissing('Idempotency-Replayed')
            ->assertExactJson(['data' => [
                'id' => $transaction->id,
                'external_ref' => 'POS-T-0001',
                'station_id' => $this->world['station']->id,
                'company_id' => $card->company_id,
                'card_no' => $card->card_no,
                'product_code' => 'DIESEL',
                'liters' => '20.00',
                'unit_price_lbp' => '80000.0000',
                'amount_lbp' => '1600000.00',
                'amount_usd' => '17.88',
                'rate_lbp_per_usd' => '89500.00000000',
                'rate_source' => 'fixture',
                'rate_effective_at' => '2026-09-28T00:00:00Z',
                'transacted_at' => '2026-09-28T08:00:00Z',
                'quota_month' => '2026-09-01',
                'odometer_km' => 45000,
            ]]);

        // Ownership and tank snapshots, receipt time and the replay fingerprint.
        $this->assertSame($card->vehicle_id, $transaction->vehicle_id);
        $this->assertSame($card->driver_id, $transaction->driver_id);
        $this->assertSame('60.00', $transaction->tank_capacity_l);
        $this->assertSame($this->world['operator']->id, $transaction->created_by);
        $this->assertSame(self::FIXTURE_NOW, $transaction->created_at?->toIso8601ZuluString());
        $this->assertSame(
            PosRequestHash::make($this->world['station']->id, 'POS-T-0001', $card->card_no, 'DIESEL', '20.00', CarbonImmutable::parse('2026-09-28T08:00:00Z'), 45000),
            $transaction->request_hash,
        );

        $this->assertUsage('20.00', '17.88');
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    public function test_identifiers_are_stored_in_capitals(): void
    {
        $card = $this->card();

        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'pos-t-lower', 'card_no' => strtolower($card->card_no)]))
            ->assertCreated()
            ->assertJsonPath('data.external_ref', 'POS-T-LOWER')
            ->assertJsonPath('data.card_no', $card->card_no);
    }

    /** T14 */
    public function test_an_identical_retry_returns_the_original_purchase_without_spending_again(): void
    {
        $first = $this->submitPurchase($this->token, $this->payload())->assertCreated();

        $this->submitPurchase($this->token, $this->payload())
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertExactJson($first->json());

        $this->assertSame(1, FuelTransaction::query()->count());
        $this->assertUsage('20.00', '17.88');
    }

    /** T14: key order, "20" vs "20.00", offset vs Z and letter case do not make a new purchase. */
    public function test_canonically_equivalent_retries_are_replays(): void
    {
        $id = $this->submitPurchase($this->token, $this->payload())->assertCreated()->json('data.id');
        $card = $this->card();

        $variants = [
            'UTC instead of Beirut offset' => $this->payload(['transacted_at' => '2026-09-28T08:00:00Z']),
            'liters without decimals' => $this->payload(['liters' => '20']),
            'liters with one decimal' => $this->payload(['liters' => '20.0']),
            'keys in another order' => array_reverse($this->payload(), true),
            'lower-case identifiers' => $this->payload(['external_ref' => 'pos-t-0001', 'card_no' => strtolower($card->card_no)]),
        ];

        foreach ($variants as $label => $payload) {
            $this->submitPurchase($this->token, $payload)->assertOk()->assertJsonPath('data.id', $id);
        }

        $this->assertSame(1, FuelTransaction::query()->count(), 'A variant created a second purchase.');
        $this->assertUsage('20.00', '17.88');
    }

    /** T14: an omitted odometer and a null odometer are the same request. */
    public function test_an_omitted_and_a_null_odometer_are_the_same_request(): void
    {
        $payload = $this->payload();
        unset($payload['odometer_km']);

        $id = $this->submitPurchase($this->token, $payload)->assertCreated()->json('data.id');
        $this->submitPurchase($this->token, $this->payload(['odometer_km' => null]))->assertOk()->assertJsonPath('data.id', $id);
    }

    /**
     * T14: the same reference with any real change is a conflict.
     *
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('changedPayloads')]
    public function test_a_changed_purchase_under_the_same_reference_is_a_conflict(array $change): void
    {
        $this->submitPurchase($this->token, $this->payload())->assertCreated();

        if (($change['card_no'] ?? null) === 'OTHER') {
            $change['card_no'] = FuelCard::factory()->create()->card_no;
        }

        $this->submitPurchase($this->token, $this->payload($change))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertSame(1, FuelTransaction::query()->count());
        $this->assertUsage('20.00', '17.88');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function changedPayloads(): array
    {
        return [
            'liters' => [['liters' => '20.01']],
            'product' => [['product_code' => 'ULP95']],
            'card' => [['card_no' => 'OTHER']],
            'time, one second later' => [['transacted_at' => '2026-09-28T08:00:01Z']],
            'odometer' => [['odometer_km' => 45001]],
            'odometer removed' => [['odometer_km' => null]],
        ];
    }

    /** T14: references are unique per station, not globally. */
    public function test_another_station_may_use_the_same_reference(): void
    {
        $this->submitPurchase($this->token, $this->payload())->assertCreated();

        $other = $this->submitPurchase($this->posToken($this->world['otherOperator']), $this->payload())->assertCreated();

        $this->assertSame($this->world['otherStation']->id, $other->json('data.station_id'));
        $this->assertSame(2, FuelTransaction::query()->count());
        $this->assertUsage('40.00', '35.76');
    }

    /** T15: replays are answered before rules that changed since. */
    public function test_a_replay_succeeds_after_the_card_is_blocked_the_price_changes_and_the_quota_is_cut(): void
    {
        $first = $this->submitPurchase($this->token, $this->payload())->assertCreated();
        $cards = app(FuelCardService::class);
        $admin = $this->world['admin'];

        $cards->updateLimits($this->card(), '1.00', '1.00', $admin);
        $cards->changeStatus($this->card(), CardStatus::Blocked, $admin);
        ProductPrice::factory()->create([
            'product_id' => $this->world['diesel']->id,
            'price_lbp' => '95000.0000',
            'effective_from' => CarbonImmutable::now()->subHours(2),
            'created_by' => $admin->id,
        ]);

        $this->submitPurchase($this->token, $this->payload())->assertOk()->assertExactJson($first->json());

        // A new request meets the block.
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'POS-T-0002']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'card_blocked');
        $this->assertSame(1, FuelTransaction::query()->count());
    }

    /** T15: an accepted event that is now older than 72 hours, with its rate expired, still replays. */
    public function test_a_replay_succeeds_after_the_event_is_older_than_72_hours(): void
    {
        // Keep the original payload: the default time is relative to "now".
        $original = $this->payload();
        $first = $this->submitPurchase($this->token, $original)->assertCreated();

        $this->travel(4)->days();
        $expired = $this->token;
        $fresh = $this->posToken($this->world['operator']);

        // The original token has expired (24 hours): authentication still comes first.
        $this->submitPurchase($expired, $original)->assertUnauthorized();

        $this->submitPurchase($fresh, $original)->assertOk()->assertExactJson($first->json());
        $this->submitPurchase($fresh, array_merge($original, ['external_ref' => 'POS-T-0002']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrorFor('transacted_at', 'error.details');
    }

    /** T15: a revoked or unknown token fails even for a replay. */
    public function test_a_bad_token_fails_before_any_replay(): void
    {
        $this->submitPurchase($this->token, $this->payload())->assertCreated();

        $this->submitPurchase('1|not-a-real-token', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->world['operator']->tokens()->delete();
        $this->submitPurchase($this->token, $this->payload())->assertUnauthorized();
    }

    /**
     * T16: each decline returns its code and writes nothing.
     *
     * @param  array<string, mixed>  $details
     */
    #[DataProvider('declines')]
    public function test_declined_purchases_change_nothing(string $case, int $status, string $code, array $details): void
    {
        $payload = $this->arrangeDecline($case);

        $response = $this->submitPurchase($this->token, $payload)
            ->assertStatus($status)
            ->assertJsonPath('error.code', $code);

        if ($details !== []) {
            $response->assertJsonPath('error.details', $details);
        }

        $this->assertSame(0, FuelTransaction::query()->count());
        $this->assertSame(0, CardMonthlyUsage::query()->count());
    }

    /**
     * @return array<string, array{string, int, string, array<string, string>}>
     */
    public static function declines(): array
    {
        return [
            'blocked card' => ['blocked card', 403, 'card_blocked', []],
            'archived card' => ['archived card', 403, 'card_inactive', []],
            'inactive company' => ['inactive company', 403, 'company_inactive', []],
            'inactive vehicle' => ['inactive vehicle', 403, 'assignment_inactive', ['assignment' => 'vehicle']],
            'inactive driver' => ['inactive driver', 403, 'assignment_inactive', ['assignment' => 'driver']],
            'product not on sale' => ['inactive product', 403, 'product_not_allowed', ['reason' => 'product_inactive']],
            'product outside the card restriction' => ['restricted product', 403, 'product_not_allowed', ['reason' => 'card_restriction']],
            'petrol into a diesel vehicle' => ['wrong fuel', 403, 'product_not_allowed', ['reason' => 'vehicle_fuel_type']],
            'unknown card' => ['unknown card', 404, 'not_found', []],
            'inactive station' => ['inactive station', 403, 'station_inactive', []],
        ];
    }

    /** T17: used + requested <= limit, to the cent. */
    public function test_the_exact_liter_limit_is_accepted_and_the_smallest_excess_refused(): void
    {
        $this->card()->forceFill(['monthly_limit_l' => '100.00', 'monthly_limit_usd' => null])->save();

        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'Q-1', 'liters' => '60.00']))->assertCreated();
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'Q-2', 'liters' => '40.00']))->assertCreated();
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'Q-3', 'liters' => '0.01']))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'quota_exceeded')
            ->assertJsonPath('error.details', ['dimension' => 'liters']);

        // 53.63 + 35.75 USD
        $this->assertUsage('100.00', '89.38');
    }

    /** T17: the USD limit is compared with the rounded amount that will be stored. */
    public function test_the_usd_limit_is_checked_against_the_stored_rounded_amount(): void
    {
        $this->card()->forceFill(['monthly_limit_l' => null, 'monthly_limit_usd' => '17.87'])->save();
        $this->submitPurchase($this->token, $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.details', ['dimension' => 'usd']);

        $this->card()->forceFill(['monthly_limit_usd' => '17.88'])->save();
        $this->submitPurchase($this->token, $this->payload())->assertCreated()->assertJsonPath('data.amount_usd', '17.88');

        $this->assertUsage('20.00', '17.88');
    }

    /** T17: null means unlimited; zero means nothing more in that dimension. */
    public function test_null_limits_are_unlimited_and_zero_limits_allow_nothing(): void
    {
        $this->card()->forceFill(['monthly_limit_l' => null, 'monthly_limit_usd' => null])->save();
        // Far more than the 60 L tank: accepted, and reported as an anomaly later (M08).
        $this->submitPurchase($this->token, $this->payload(['liters' => '5000.00']))
            ->assertCreated()
            ->assertJsonPath('data.amount_usd', '4469.27');
        $this->assertTrue(FuelTransaction::query()->sole()->exceedsTankCapacity());

        $this->card()->forceFill(['monthly_limit_l' => '0.00'])->save();
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'Z-1', 'liters' => '0.01']))
            ->assertForbidden()->assertJsonPath('error.details', ['dimension' => 'liters']);

        $this->card()->forceFill(['monthly_limit_l' => null, 'monthly_limit_usd' => '0.00'])->save();
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'Z-2', 'liters' => '0.01']))
            ->assertForbidden()->assertJsonPath('error.details', ['dimension' => 'usd']);

        $this->assertSame(1, FuelTransaction::query()->count());
    }

    /**
     * T18: structural validation, before any lookup.
     *
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('malformedRequests')]
    public function test_malformed_requests_are_refused_without_writing(array $change, string $field): void
    {
        $this->submitPurchase($this->token, $this->payload($change))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrorFor($field, 'error.details');

        $this->assertSame(0, FuelTransaction::query()->count());
        $this->assertSame(0, CardMonthlyUsage::query()->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedRequests(): array
    {
        return [
            'zero liters' => [['liters' => '0'], 'liters'],
            'negative liters' => [['liters' => '-5.00'], 'liters'],
            'liters as a JSON number' => [['liters' => 20.5], 'liters'],
            'liters as a JSON integer' => [['liters' => 20], 'liters'],
            'liters with an exponent' => [['liters' => '2e1'], 'liters'],
            'liters with three decimals' => [['liters' => '20.001'], 'liters'],
            'liters with a comma' => [['liters' => '20,5'], 'liters'],
            'liters beyond storage' => [['liters' => '123456789.00'], 'liters'],
            'missing liters' => [['liters' => null], 'liters'],
            'time without offset' => [['transacted_at' => '2026-09-28T08:00:00'], 'transacted_at'],
            'time with fractional seconds' => [['transacted_at' => '2026-09-28T08:00:00.500Z'], 'transacted_at'],
            'impossible date' => [['transacted_at' => '2026-02-30T08:00:00Z'], 'transacted_at'],
            'date only' => [['transacted_at' => '2026-09-28'], 'transacted_at'],
            'lower-case z' => [['transacted_at' => '2026-09-28T08:00:00z'], 'transacted_at'],
            'odometer as a string' => [['odometer_km' => '45000'], 'odometer_km'],
            'negative odometer' => [['odometer_km' => -1], 'odometer_km'],
            'fractional odometer' => [['odometer_km' => 45000.5], 'odometer_km'],
            'reference with a space' => [['external_ref' => 'POS 1'], 'external_ref'],
            'reference too long' => [['external_ref' => str_repeat('A', 101)], 'external_ref'],
            'card number with an underscore' => [['card_no' => 'FF_ATLAS_001'], 'card_no'],
            'unknown product code' => [['product_code' => 'KEROSENE'], 'product_code'],
            'lower-case product code' => [['product_code' => 'diesel'], 'product_code'],
            'payload station_id' => [['station_id' => 1], 'station_id'],
            'caller-supplied amount' => [['amount_usd' => '1.00'], 'amount_usd'],
        ];
    }

    /** T18: the 72-hour window, inclusive at both ends. */
    public function test_the_event_time_must_be_within_the_last_72_hours(): void
    {
        ExchangeRate::factory()->create(['effective_at' => CarbonImmutable::parse('2026-09-25T00:00:00Z')]);

        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'W-1', 'transacted_at' => '2026-09-28T09:00:01Z']))
            ->assertUnprocessable()->assertJsonValidationErrorFor('transacted_at', 'error.details');
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'W-2', 'transacted_at' => '2026-09-25T08:59:59Z']))
            ->assertUnprocessable()->assertJsonValidationErrorFor('transacted_at', 'error.details');

        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'W-3', 'transacted_at' => '2026-09-28T09:00:00Z']))->assertCreated();
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'W-4', 'transacted_at' => '2026-09-25T09:00:00Z']))->assertCreated();
    }

    /** T19: Beirut months, with the offset changing from +03:00 to +02:00 on 25 October 2026. */
    public function test_the_quota_month_follows_the_beirut_calendar_across_daylight_saving(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01T06:00:00Z'));
        ExchangeRate::factory()->create(['effective_at' => CarbonImmutable::parse('2026-10-31T00:00:00Z')]);
        $token = $this->posToken($this->world['operator']);

        // 23:59:59 on 31 October in Beirut (UTC+2 after the change).
        $this->submitPurchase($token, $this->payload(['external_ref' => 'M-1', 'transacted_at' => '2026-10-31T21:59:59Z']))
            ->assertCreated()->assertJsonPath('data.quota_month', '2026-10-01');
        // Midnight, 1 November in Beirut.
        $this->submitPurchase($token, $this->payload(['external_ref' => 'M-2', 'transacted_at' => '2026-11-01T00:00:00+02:00']))
            ->assertCreated()->assertJsonPath('data.quota_month', '2026-11-01')->assertJsonPath('data.transacted_at', '2026-10-31T22:00:00Z');

        $this->assertSame(
            ['2026-10-01' => '20.00', '2026-11-01' => '20.00'],
            CardMonthlyUsage::query()->orderBy('month_start')->get()->mapWithKeys(fn (CardMonthlyUsage $u) => [$u->month_start->format('Y-m-d') => $u->used_l])->all(),
        );
    }

    /** T19: a late event from last month counts against last month, with the card's current limits. */
    public function test_a_late_event_from_last_month_goes_to_last_months_counter(): void
    {
        // 08:00 on 1 October in Beirut (UTC+3).
        $this->travelTo(CarbonImmutable::parse('2026-10-01T05:00:00Z'));
        $token = $this->posToken($this->world['operator']);

        // 21:00 UTC on 30 September is exactly midnight in Beirut: October.
        $this->submitPurchase($token, $this->payload(['external_ref' => 'L-1', 'transacted_at' => '2026-09-30T21:00:00Z']))
            ->assertCreated()->assertJsonPath('data.quota_month', '2026-10-01');
        // One second earlier is still 30 September in Beirut.
        $this->submitPurchase($token, $this->payload(['external_ref' => 'L-2', 'transacted_at' => '2026-09-30T20:59:59Z']))
            ->assertCreated()->assertJsonPath('data.quota_month', '2026-09-01');

        // The limit applies as it is now, even to a September event.
        $this->card()->forceFill(['monthly_limit_l' => '30.00'])->save();
        $this->submitPurchase($token, $this->payload(['external_ref' => 'L-3', 'liters' => '10.01', 'transacted_at' => '2026-09-30T20:00:00Z']))
            ->assertForbidden()->assertJsonPath('error.code', 'quota_exceeded');
        $this->submitPurchase($token, $this->payload(['external_ref' => 'L-4', 'liters' => '10.00', 'transacted_at' => '2026-09-30T20:00:00Z']))
            ->assertCreated()->assertJsonPath('data.quota_month', '2026-09-01');

        $this->assertSame(['30.00', '20.00'], CardMonthlyUsage::query()->orderBy('month_start')->pluck('used_l')->all());
    }

    /** T10 (ledger part): the price in effect at the event time, not the latest one. */
    public function test_the_price_in_effect_at_the_event_time_is_used(): void
    {
        ProductPrice::factory()->create([
            'product_id' => $this->world['diesel']->id,
            'price_lbp' => '85000.0000',
            'effective_from' => CarbonImmutable::parse('2026-09-28T08:30:00Z'),
            'created_by' => $this->world['admin']->id,
        ]);

        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'P-1', 'transacted_at' => '2026-09-28T08:29:59Z']))
            ->assertCreated()->assertJsonPath('data.unit_price_lbp', '80000.0000');
        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'P-2', 'transacted_at' => '2026-09-28T08:30:00Z']))
            ->assertCreated()->assertJsonPath('data.unit_price_lbp', '85000.0000')->assertJsonPath('data.amount_lbp', '1700000.00');
    }

    /** T12 (ledger part): a valid manual override converts new purchases and is snapshotted. */
    public function test_a_valid_manual_override_converts_new_purchases(): void
    {
        ExchangeRate::factory()->manual()->create([
            'rate' => '90000.00000000',
            'effective_at' => CarbonImmutable::parse('2026-09-28T07:30:00Z'),
            'expires_at' => CarbonImmutable::parse('2026-09-28T10:30:00Z'),
        ]);

        $this->submitPurchase($this->token, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.rate_source', 'manual')
            ->assertJsonPath('data.rate_lbp_per_usd', '90000.00000000')
            // 1600000 / 90000 = 17.777... rounded half-up.
            ->assertJsonPath('data.amount_usd', '17.78');
    }

    /** T12 / T23: price and rate failures change nothing. */
    public function test_missing_rate_or_price_declines_without_writing(): void
    {
        config(['fleetfuel.exchange_rates.mode' => 'live']);
        $this->submitPurchase($this->token, $this->payload())
            ->assertStatus(503)->assertJsonPath('error.code', 'rate_unavailable');

        config(['fleetfuel.exchange_rates.mode' => 'fixture']);
        $ulp98 = Product::factory()->forCode(ProductCode::Ulp98)->create();
        $petrolCard = FuelCard::factory()->assignedTo(Vehicle::factory()->petrol()->create(['company_id' => $this->world['company']->id]))->create();
        $this->submitPurchase($this->token, $this->payload(['card_no' => $petrolCard->card_no, 'product_code' => $ulp98->code]))
            ->assertUnprocessable()->assertJsonPath('error.code', 'price_unavailable');

        $this->assertSame(0, FuelTransaction::query()->count());
        $this->assertSame(0, CardMonthlyUsage::query()->count());
    }

    /** T23: a failure after the ledger insert rolls back the row and the counter together. */
    public function test_a_failure_after_the_ledger_insert_rolls_back_both_writes(): void
    {
        $this->submitPurchase($this->token, $this->payload())->assertCreated();

        // Fail while incrementing the existing counter, after the new ledger row was inserted.
        CardMonthlyUsage::updating(fn () => throw new RuntimeException('Forced failure for the rollback test.'));

        $this->submitPurchase($this->token, $this->payload(['external_ref' => 'POS-T-0002']))
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'internal_error')
            ->assertJsonMissing(['message' => 'Forced failure for the rollback test.']);

        $this->assertSame(['POS-T-0001'], FuelTransaction::query()->pluck('external_ref')->all());
        $this->assertUsage('20.00', '17.88');
        $this->assertSame([], app(UsageReconciliation::class)->mismatches());
    }

    /** T06 for this route: token, role and ability are all required. */
    public function test_only_an_operator_token_with_the_create_ability_may_submit(): void
    {
        $this->postJson('/api/v1/transactions', $this->payload())
            ->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

        $this->submitPurchase($this->posToken($this->world['manager']), $this->payload())
            ->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $readOnly = $this->world['operator']->createToken('read-only', ['reference:read', 'transactions:read'])->plainTextToken;
        $this->submitPurchase($readOnly, $this->payload())->assertForbidden();

        // A browser session is not an API credential.
        $this->startNewRequestCycle();
        $this->actingAs($this->world['operator'])->postJson('/api/v1/transactions', $this->payload())->assertUnauthorized();

        $this->assertSame(0, FuelTransaction::query()->count());
    }

    public function test_ledger_rows_have_no_update_or_delete_route(): void
    {
        $id = $this->submitPurchase($this->token, $this->payload())->assertCreated()->json('data.id');

        foreach (['putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->startNewRequestCycle();
            $this->withToken($this->token)->{$method}("/api/v1/transactions/{$id}", ['liters' => '1.00'])
                ->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
        }

        $this->assertSame('20.00', FuelTransaction::query()->sole()->liters);
    }

    /** 60 purchase requests per minute per station, shared by its operators. */
    public function test_pos_writes_are_limited_per_station(): void
    {
        $colleague = User::factory()->stationOperator($this->world['station'])->create();
        $colleagueToken = $this->posToken($colleague);

        for ($i = 1; $i <= 60; $i++) {
            $this->submitPurchase($this->token, [])->assertUnprocessable();
        }

        $this->submitPurchase($colleagueToken, [])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertHeader('Retry-After');

        // Another station is unaffected, and the window resets after a minute.
        $this->submitPurchase($this->posToken($this->world['otherOperator']), [])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->submitPurchase($colleagueToken, [])->assertUnprocessable();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $this->posPayload($this->card()->card_no, $overrides);
    }

    private function card(): FuelCard
    {
        return $this->world['card']->refresh();
    }

    private function assertUsage(string $liters, string $usd): void
    {
        $usage = CardMonthlyUsage::query()->where('fuel_card_id', $this->world['card']->id)->sole();
        $this->assertSame([$liters, $usd], [$usage->used_l, $usage->used_usd]);
    }

    /**
     * @return array<string, mixed>
     */
    private function arrangeDecline(string $case): array
    {
        $card = $this->card();

        return match ($case) {
            'blocked card' => $this->payloadAfter(fn () => $card->forceFill(['status' => CardStatus::Blocked])->save()),
            'archived card' => $this->payloadAfter(fn () => $card->forceFill(['status' => CardStatus::Archived])->save()),
            'inactive company' => $this->payloadAfter(fn () => $this->world['company']->forceFill(['status' => CompanyStatus::Inactive])->save()),
            'inactive vehicle' => $this->payloadAfter(fn () => $this->world['vehicle']->forceFill(['is_active' => false])->save()),
            'inactive driver' => $this->payloadAfter(fn () => $this->world['driver']->forceFill(['is_active' => false])->save()),
            'inactive product' => $this->payloadAfter(fn () => $this->world['diesel']->forceFill(['is_active' => false])->save()),
            'restricted product' => $this->payload(['product_code' => 'ULP95']),
            'wrong fuel' => $this->posPayload(
                FuelCard::factory()->assignedTo($this->world['vehicle'])->create()->card_no,
                ['product_code' => 'ULP95'],
            ),
            'unknown card' => $this->payload(['card_no' => 'FF-NO-SUCH-CARD']),
            'inactive station' => $this->payloadAfter(fn () => $this->world['station']->forceFill(['is_active' => false])->save()),
            default => throw new LogicException("Unknown decline case {$case}."),
        };
    }

    /**
     * Apply a setup change, then return the default payload.
     *
     * @return array<string, mixed>
     */
    private function payloadAfter(callable $change): array
    {
        $change();

        return $this->payload();
    }
}
