<?php

namespace Tests\Feature\Pricing;

use App\Enums\ProductCode;
use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Exceptions\PriceUnavailable;
use App\Exceptions\RateUnavailable;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\PriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T09, T10 and T12 on MySQL: the price and rate chosen for an instant, the
 * rounded amounts, and refusal when either is missing. The resolver only
 * reads the database; no HTTP is involved (and none is allowed in tests).
 */
class PriceResolverTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-09-28T09:00:00Z';

    private PriceResolver $resolver;

    private Product $diesel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW));
        config(['fleetfuel.exchange_rates.mode' => 'fixture']);

        $this->resolver = app(PriceResolver::class);
        $this->admin = User::factory()->admin()->create();
        $this->diesel = Product::factory()->forCode(ProductCode::Diesel)->create();
    }

    /** T09: the documented example, through stored rows. */
    public function test_twenty_liters_at_80000_lbp_and_89500_yield_1600000_lbp_and_17_88_usd(): void
    {
        $this->price('80000.0000', '2026-09-01T00:00:00Z');
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-28T00:00:00Z');

        $quote = $this->resolver->quote($this->diesel, '20', CarbonImmutable::parse(self::NOW));

        $this->assertSame('20.00', $quote->liters);
        $this->assertSame('80000.0000', $quote->price->price_lbp);
        $this->assertSame('89500.00000000', $quote->rate->rate);
        $this->assertSame('1600000.00', $quote->amountLbp);
        $this->assertSame('17.88', $quote->amountUsd);
    }

    /** T09: malformed or excess-scale liters never reach the arithmetic. */
    #[DataProvider('badLiters')]
    public function test_malformed_liters_are_a_validation_error(string $liters): void
    {
        $this->price('80000.0000', '2026-09-01T00:00:00Z');
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-28T00:00:00Z');

        try {
            $this->resolver->quote($this->diesel, $liters, CarbonImmutable::parse(self::NOW));
            $this->fail("Liters {$liters} were accepted.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('liters', $e->errors());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badLiters(): array
    {
        return [
            'excess scale' => ['20.001'],
            'exponent' => ['2e1'],
            'negative' => ['-20'],
            'zero' => ['0'],
            'comma' => ['20,5'],
            'too many digits' => ['123456789'],
        ];
    }

    /** T09: valid inputs whose product overflows amount_lbp DECIMAL(20,2). */
    public function test_an_amount_that_would_overflow_its_column_is_a_validation_error(): void
    {
        $this->price('99999999999999.9999', '2026-09-01T00:00:00Z');
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-28T00:00:00Z');

        $this->expectException(ValidationException::class);
        $this->resolver->quote($this->diesel, '99999999.99', CarbonImmutable::parse(self::NOW));
    }

    /** T10: a price applies from its exact effective instant, not a second earlier. */
    public function test_the_price_changes_exactly_at_its_effective_instant(): void
    {
        $this->price('78000.0000', '2026-09-01T00:00:00Z');
        $this->price('80000.0000', '2026-09-28T06:00:00Z');

        $this->assertSame('78000.0000', $this->resolver->priceAt($this->diesel, CarbonImmutable::parse('2026-09-28T05:59:59Z'))->price_lbp);
        $this->assertSame('80000.0000', $this->resolver->priceAt($this->diesel, CarbonImmutable::parse('2026-09-28T06:00:00Z'))->price_lbp);
        // A Beirut-time instant is the same moment (UTC+3 in September).
        $this->assertSame('80000.0000', $this->resolver->priceAt($this->diesel, CarbonImmutable::parse('2026-09-28T09:00:00+03:00'))->price_lbp);
    }

    /** T10: publishing a later price never changes what an earlier instant resolves to. */
    public function test_a_later_price_does_not_change_an_earlier_resolution(): void
    {
        $this->price('78000.0000', '2026-09-01T00:00:00Z');
        $purchaseTime = CarbonImmutable::parse('2026-09-20T10:00:00Z');
        $before = $this->resolver->priceAt($this->diesel, $purchaseTime);

        $this->price('95000.0000', '2026-09-28T08:00:00Z');

        $this->assertTrue($before->is($this->resolver->priceAt($this->diesel, $purchaseTime)));
        $this->assertSame('95000.0000', $this->resolver->priceAt($this->diesel, CarbonImmutable::parse(self::NOW))->price_lbp);
    }

    /** T10: no eligible price is 422 price_unavailable, never a zero price. */
    public function test_no_price_yet_is_price_unavailable(): void
    {
        $this->price('80000.0000', '2026-09-28T10:00:00Z');
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-28T00:00:00Z');

        try {
            $this->resolver->quote($this->diesel, '20', CarbonImmutable::parse(self::NOW));
            $this->fail('A purchase was priced before any price took effect.');
        } catch (PriceUnavailable $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('price_unavailable', $e->errorCode);
        }
    }

    /** T12: a valid manual override wins; the latest one if several overlap. */
    public function test_a_valid_manual_override_takes_precedence(): void
    {
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-28T00:00:00Z');
        $this->assertSame(RateSource::Fixture, $this->resolver->rateAt(CarbonImmutable::parse(self::NOW))->source);

        $this->rate(RateSource::Manual, '90000.00000000', '2026-09-28T07:00:00Z', hours: 24);
        $this->rate(RateSource::Manual, '90500.00000000', '2026-09-28T08:00:00Z', hours: 2);

        $rate = $this->resolver->rateAt(CarbonImmutable::parse(self::NOW));
        $this->assertSame(RateSource::Manual, $rate->source);
        $this->assertSame('90500.00000000', $rate->rate);

        // After the later override expires, the earlier one still applies.
        $this->assertSame('90000.00000000', $this->resolver->rateAt(CarbonImmutable::parse('2026-09-28T10:00:00Z'))->rate);
        // After both expire, the fixture applies again.
        $this->assertSame(RateSource::Fixture, $this->resolver->rateAt(CarbonImmutable::parse('2026-09-29T07:00:00Z'))->source);
    }

    /** T12: eligibility is effective_at <= T < expires_at. */
    public function test_a_rate_is_eligible_from_its_effective_instant_until_just_before_expiry(): void
    {
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-28T00:00:00Z');

        $this->assertNull($this->resolver->findRate(CarbonImmutable::parse('2026-09-27T23:59:59Z')));
        $this->assertNotNull($this->resolver->findRate(CarbonImmutable::parse('2026-09-28T00:00:00Z')));
        $this->assertNotNull($this->resolver->findRate(CarbonImmutable::parse('2026-09-30T23:59:59Z')));
        $this->assertNull($this->resolver->findRate(CarbonImmutable::parse('2026-10-01T00:00:00Z')));
    }

    /** T12 / D09: an expired or missing rate is 503 rate_unavailable, never 1:1 or zero. */
    public function test_an_expired_or_missing_rate_is_rate_unavailable(): void
    {
        $this->price('80000.0000', '2026-09-01T00:00:00Z');
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-24T00:00:00Z');

        try {
            $this->resolver->quote($this->diesel, '20', CarbonImmutable::parse(self::NOW));
            $this->fail('A purchase was converted with an expired rate.');
        } catch (RateUnavailable $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame('rate_unavailable', $e->errorCode);
        }
    }

    /** T12: live mode ignores fixture rows; fixture mode ignores provider rows. */
    public function test_each_mode_reads_only_its_own_automated_source(): void
    {
        $this->rate(RateSource::Fixture, '89500.00000000', '2026-09-28T00:00:00Z');
        $at = CarbonImmutable::parse(self::NOW);

        $this->assertNull($this->resolver->findRate($at, RateMode::Live));

        $this->rate(RateSource::Provider, '89480.12500000', '2026-09-28T00:02:11Z');

        $this->assertSame('89480.12500000', $this->resolver->rateAt($at, RateMode::Live)->rate);
        $this->assertSame('89500.00000000', $this->resolver->rateAt($at, RateMode::Fixture)->rate);

        // The configured mode is the default.
        config(['fleetfuel.exchange_rates.mode' => 'live']);
        $this->assertSame(RateSource::Provider, $this->resolver->rateAt($at)->source);
    }

    /** A manual override applies in live mode too. */
    public function test_a_manual_override_applies_in_live_mode(): void
    {
        $this->rate(RateSource::Provider, '89480.00000000', '2026-09-28T00:00:00Z');
        $this->rate(RateSource::Manual, '91000.00000000', '2026-09-28T08:30:00Z', hours: 1);

        $this->assertSame('91000.00000000', $this->resolver->rateAt(CarbonImmutable::parse(self::NOW), RateMode::Live)->rate);
    }

    private function price(string $lbp, string $from): ProductPrice
    {
        return ProductPrice::factory()->create([
            'product_id' => $this->diesel->id,
            'price_lbp' => $lbp,
            'effective_from' => CarbonImmutable::parse($from),
            'created_by' => $this->admin->id,
        ]);
    }

    private function rate(RateSource $source, string $rate, string $effectiveAt, int $hours = 72): ExchangeRate
    {
        $effective = CarbonImmutable::parse($effectiveAt);

        return ExchangeRate::factory()->create([
            'source' => $source,
            'rate' => $rate,
            'effective_at' => $effective,
            'expires_at' => $effective->addHours($hours),
            'fetched_at' => $source === RateSource::Provider ? $effective->addMinutes(5) : null,
            'created_by' => $source === RateSource::Manual ? $this->admin->id : null,
            'reason' => $source === RateSource::Manual ? 'Test override' : null,
        ]);
    }
}
