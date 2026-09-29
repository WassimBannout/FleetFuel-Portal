<?php

namespace Tests\Feature\Pricing;

use App\Enums\ProductCode;
use App\Enums\RateSource;
use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\FuelTransaction;
use App\Models\ProductPrice;
use App\Services\PriceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The price timeline: every role reads it, an admin publishes now-or-later
 * prices (audited), nothing published is ever changed, and pages that show
 * an indicative USD price say which rate they used.
 */
class ProductPriceScreensTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        config(['fleetfuel.exchange_rates.mode' => 'fixture']);
        $this->seedDemo();
    }

    public function test_an_admin_publishes_a_price_that_starts_now_and_it_is_audited(): void
    {
        $diesel = $this->product(ProductCode::Diesel);
        $this->actingAs($this->admin());

        $this->post(route('products.prices.store', $diesel), ['price_lbp' => '82000', 'effective_from' => ''])
            ->assertRedirect(route('products.prices.index', $diesel))
            ->assertSessionHasNoErrors();

        $price = ProductPrice::query()->where('product_id', $diesel->id)->latest('effective_from')->firstOrFail();
        $this->assertSame('82000.0000', $price->price_lbp);
        $this->assertSame(self::FIXTURE_NOW, $price->effective_from->toIso8601ZuluString());
        $this->assertSame($this->admin()->id, $price->created_by);

        $audit = AuditLog::query()->where('action', 'product_price.published')->sole();
        $this->assertSame($this->admin()->id, $audit->user_id);
        // assertEquals: a MySQL JSON column does not keep key order.
        $this->assertEquals(['product_code' => 'DIESEL', 'price_lbp' => '82000.0000', 'effective_from' => self::FIXTURE_NOW], $audit->new_values);

        $this->get(route('products.prices.index', $diesel))->assertOk()
            ->assertSeeInOrder(['Current price', '82,000.0000', 'LBP per liter']);
        // 82000 / 89500 = 0.91620...
        $this->get(route('products.index'))->assertOk()->assertSee('82,000.0000')->assertSee('0.9162');
    }

    public function test_a_future_price_is_scheduled_and_does_not_change_the_current_price(): void
    {
        $diesel = $this->product(ProductCode::Diesel);
        $this->actingAs($this->admin());

        // 06:00 in Beirut on 1 October is 03:00 UTC (UTC+3).
        $this->post(route('products.prices.store', $diesel), ['price_lbp' => '81500.5', 'effective_from' => '2026-10-01T06:00'])
            ->assertSessionHasNoErrors();

        $scheduled = ProductPrice::query()->where('product_id', $diesel->id)->where('price_lbp', '81500.5000')->sole();
        $this->assertSame('2026-10-01T03:00:00Z', $scheduled->effective_from->toIso8601ZuluString());

        $resolver = app(PriceResolver::class);
        $this->assertSame('80000.0000', $resolver->priceAt($diesel, CarbonImmutable::now())->price_lbp);
        $this->assertSame('81500.5000', $resolver->priceAt($diesel, CarbonImmutable::parse('2026-10-01T03:00:00Z'))->price_lbp);

        $this->get(route('products.prices.index', $diesel))->assertOk()
            ->assertSee('Scheduled')
            ->assertSeeInOrder(['Current price', '80,000.0000']);
    }

    /** T10: purchases keep their price snapshot when a new price is published. */
    public function test_publishing_a_price_never_changes_recorded_purchases(): void
    {
        $diesel = $this->product(ProductCode::Diesel);
        $before = FuelTransaction::query()->where('product_id', $diesel->id)->orderBy('id')->get(['id', 'product_price_id', 'unit_price_lbp', 'amount_lbp', 'amount_usd'])->toArray();
        $this->assertNotEmpty($before);

        $this->actingAs($this->admin())
            ->post(route('products.prices.store', $diesel), ['price_lbp' => '95000'])
            ->assertSessionHasNoErrors();

        $after = FuelTransaction::query()->where('product_id', $diesel->id)->orderBy('id')->get(['id', 'product_price_id', 'unit_price_lbp', 'amount_lbp', 'amount_usd'])->toArray();
        $this->assertSame($before, $after);
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('invalidPrices')]
    public function test_invalid_prices_are_refused_and_nothing_is_written(array $input, string $field): void
    {
        $diesel = $this->product(ProductCode::Diesel);
        $count = ProductPrice::query()->count();

        $this->actingAs($this->admin())
            ->from(route('products.prices.index', $diesel))
            ->post(route('products.prices.store', $diesel), $input)
            ->assertRedirect(route('products.prices.index', $diesel))
            ->assertSessionHasErrors($field);

        $this->assertSame($count, ProductPrice::query()->count());
        $this->assertFalse(AuditLog::query()->where('action', 'product_price.published')->exists());
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPrices(): array
    {
        return [
            'missing price' => [['price_lbp' => ''], 'price_lbp'],
            'zero' => [['price_lbp' => '0'], 'price_lbp'],
            'negative' => [['price_lbp' => '-80000'], 'price_lbp'],
            'excess decimals' => [['price_lbp' => '80000.00001'], 'price_lbp'],
            'exponent' => [['price_lbp' => '8e4'], 'price_lbp'],
            'thousands separator' => [['price_lbp' => '80,000'], 'price_lbp'],
            'beyond DECIMAL(18,4)' => [['price_lbp' => '123456789012345'], 'price_lbp'],
            // 11:59 in Beirut is 08:59 UTC, a minute before the frozen clock.
            'start in the past' => [['price_lbp' => '82000', 'effective_from' => '2026-09-28T11:59'], 'effective_from'],
            'start not a time' => [['price_lbp' => '82000', 'effective_from' => 'tomorrow'], 'effective_from'],
        ];
    }

    public function test_two_prices_cannot_start_at_the_same_instant(): void
    {
        $diesel = $this->product(ProductCode::Diesel);
        $this->actingAs($this->admin());

        $this->post(route('products.prices.store', $diesel), ['price_lbp' => '81000', 'effective_from' => '2026-10-01T06:00'])->assertSessionHasNoErrors();
        $this->post(route('products.prices.store', $diesel), ['price_lbp' => '81100', 'effective_from' => '2026-10-01T06:00'])->assertSessionHasErrors('effective_from');

        $this->assertSame(1, ProductPrice::query()->where('effective_from', CarbonImmutable::parse('2026-10-01T03:00:00Z'))->count());
    }

    /** T05: managers and operators read the timeline but cannot publish. */
    public function test_other_roles_read_prices_but_cannot_publish(): void
    {
        $diesel = $this->product(ProductCode::Diesel);

        foreach ([$this->atlasManager(), $this->operator()] as $user) {
            $this->startNewRequestCycle();
            $this->actingAs($user);

            $this->get(route('products.prices.index', $diesel))->assertOk()
                ->assertSee('80,000.0000')
                ->assertDontSee('Publish a price')
                ->assertDontSee('Published by');
            $this->post(route('products.prices.store', $diesel), ['price_lbp' => '1'])->assertForbidden();
        }

        $this->assertFalse(ProductPrice::query()->where('price_lbp', '1.0000')->exists());
    }

    /** An inactive product's prices are hidden from non-admins, like the product itself. */
    public function test_an_inactive_products_timeline_is_admin_only(): void
    {
        $ulp98 = $this->product(ProductCode::Ulp98);
        $this->actingAs($this->admin())->patch(route('products.active', $ulp98), ['is_active' => '0'])->assertSessionHasNoErrors();

        $this->get(route('products.prices.index', $ulp98))->assertOk();

        $this->startNewRequestCycle();
        $this->actingAs($this->atlasManager())->get(route('products.prices.index', $ulp98))->assertNotFound();
    }

    /** Attribution appears only where the provider's rate is used. */
    public function test_the_provider_attribution_is_shown_when_a_provider_rate_is_used(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('products.index'))->assertOk()
            ->assertSee('fixture (synthetic) rate')
            ->assertDontSee('Rates By Exchange Rate API');

        config(['fleetfuel.exchange_rates.mode' => 'live']);
        ExchangeRate::factory()->provider()->create([
            'rate' => '89480.00000000',
            'effective_at' => CarbonImmutable::parse('2026-09-28T00:02:31Z'),
        ]);

        $this->get(route('products.index'))->assertOk()
            ->assertSeeInOrder(['provider rate of', '89,480.00000000'])
            ->assertSee('<a href="https://www.exchangerate-api.com">Rates By Exchange Rate API</a>', false);
        $this->get(route('products.prices.index', $this->product(ProductCode::Diesel)))->assertOk()
            ->assertSee('Rates By Exchange Rate API');
    }

    public function test_without_a_valid_rate_no_indicative_usd_is_invented(): void
    {
        config(['fleetfuel.exchange_rates.mode' => 'live']);
        $this->assertFalse(ExchangeRate::query()->where('source', RateSource::Provider)->where('expires_at', '>', now())->exists());

        $this->actingAs($this->atlasManager())->get(route('products.index'))->assertOk()
            ->assertSee('No valid USD/LBP rate right now');
    }

    public function test_price_pages_never_call_the_provider(): void
    {
        Http::fake();
        config(['fleetfuel.exchange_rates.mode' => 'live']);
        $this->actingAs($this->admin());

        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.prices.index', $this->product(ProductCode::Diesel)))->assertOk();

        Http::assertNothingSent();
    }
}
