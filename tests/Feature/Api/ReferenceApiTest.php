<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Services\PriceResolver;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CallsApi;
use Tests\Concerns\ChecksOpenApiContract;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * GET /api/v1/stations and GET /api/v1/products/prices on the demo seed
 * (as of 2026-09-28T09:00:00Z, docs/examples/fixtures.json). Every response
 * is also checked against openapi.json.
 */
class ReferenceApiTest extends TestCase
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

    public function test_every_role_lists_only_active_stations_in_name_order(): void
    {
        foreach ([$this->admin(), $this->atlasManager(), $this->operator()] as $user) {
            $response = $this->stations('', $this->apiToken($user))->assertOk();

            // South Demo Station is inactive: hidden even from the admin here.
            $this->assertSame(['Harbor Demo Station', 'North Demo Station'], $response->json('data.*.name'));
            $response->assertJsonPath('data.0', [
                'id' => $this->station('Harbor Demo Station')->id,
                'name' => 'Harbor Demo Station',
                'district' => 'Beirut',
                'governorate' => 'Beirut',
                'is_active' => true,
            ])->assertJsonPath('meta.total', 2);
        }
    }

    public function test_the_station_list_filters_by_governorate_and_paginates(): void
    {
        $token = $this->apiToken($this->operator());

        $this->assertSame(['North Demo Station'], $this->stations('?governorate=North', $token)->assertOk()->json('data.*.name'));
        $this->assertSame([], $this->stations('?governorate=South', $token)->assertOk()->json('data'));

        $second = $this->stations('?per_page=1&page=2', $token)->assertOk();
        $this->assertSame(['North Demo Station'], $second->json('data.*.name'));
        $second->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.next', null);
        $this->assertStringContainsString('page=1', (string) $second->json('links.prev'));
    }

    public function test_the_station_list_refuses_bad_query_parameters(): void
    {
        $token = $this->apiToken($this->operator());

        $this->stations('?per_page=0', $token)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->stations('?per_page=101', $token)->assertUnprocessable();
        $this->stations('?page=0', $token)->assertUnprocessable();
        $this->stations('?is_active=0', $token)->assertUnprocessable()->assertJsonPath('error.details.is_active.0', 'This field is not allowed.');
    }

    public function test_reference_endpoints_need_a_token_with_reference_read(): void
    {
        $withoutAbility = $this->apiToken($this->operator(), ['transactions:read']);

        $this->stations('', null)->assertUnauthorized();
        $this->prices('', null)->assertUnauthorized();
        $this->stations('', $withoutAbility)->assertForbidden();
        $this->prices('', $withoutAbility)->assertForbidden();
    }

    public function test_current_prices_use_the_price_and_fixture_rate_in_effect_now(): void
    {
        $response = $this->prices('', $this->apiToken($this->operator()))->assertOk();

        // 80000 / 89500 = 0.893854... -> 0.8939 (half-up, 4 places).
        $this->assertSame([
            ['DIESEL', '80000.0000', '0.8939'],
            ['ULP95', '85000.0000', '0.9497'],
            ['ULP98', '88000.0000', '0.9832'],
        ], array_map(fn (array $row): array => [$row['product_code'], $row['unit_price_lbp'], $row['indicative_unit_price_usd']], $response->json('data')));

        $response->assertJsonPath('data.0.unit', 'L')
            // Current prices start at the Beirut month start; the rate is the day's fixture observation.
            ->assertJsonPath('data.0.effective_from', '2026-08-31T21:00:00Z')
            ->assertJsonPath('data.0.rate_effective_at', '2026-09-28T00:00:00Z')
            ->assertJsonPath('data.0.rate_source', 'fixture');
    }

    public function test_an_earlier_instant_uses_the_prices_and_rate_in_effect_then(): void
    {
        $at = CarbonImmutable::parse('2026-08-15T12:00:00+03:00');
        $rate = app(PriceResolver::class)->rateAt($at);

        $response = $this->prices('?'.http_build_query(['at' => '2026-08-15T12:00:00+03:00']), $this->apiToken($this->atlasManager()))->assertOk();

        $this->assertSame(
            [DemoSeeder::PREVIOUS_PRICES['DIESEL'], DemoSeeder::PREVIOUS_PRICES['ULP95'], DemoSeeder::PREVIOUS_PRICES['ULP98']],
            $response->json('data.*.unit_price_lbp'),
        );
        $response->assertJsonPath('data.0.rate_effective_at', $rate->effective_at->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.0.indicative_unit_price_usd', '0.8715'); // 78000 / 89500 = 0.87150...
    }

    public function test_the_instant_needs_an_offset_and_must_lie_within_the_last_366_days(): void
    {
        $token = $this->apiToken($this->operator());

        foreach (['2026-09-28T10:00:00', '2026-09-28T09:00:01Z', '2025-09-26T09:00:00Z', 'yesterday'] as $at) {
            $this->prices('?'.http_build_query(['at' => $at]), $token)->assertUnprocessable()->assertJsonValidationErrors('at', 'error.details');
        }

        $this->prices('?'.http_build_query(['at' => '2026-09-28T09:00:00Z']), $token)->assertOk();
        $this->prices('?'.http_build_query(['at' => '2026-09-28T12:00:00+03:00']), $token)->assertOk();
        $this->prices('?on=2026-09-28', $token)->assertUnprocessable();
    }

    public function test_an_inactive_product_is_not_listed(): void
    {
        Product::query()->where('code', 'ULP98')->update(['is_active' => false]);

        $this->assertSame(['DIESEL', 'ULP95'], $this->prices('', $this->apiToken($this->operator()))->assertOk()->json('data.*.product_code'));
    }

    public function test_a_missing_price_or_rate_fails_instead_of_guessing(): void
    {
        // Before the first seeded price: 422 price_unavailable.
        $this->prices('?'.http_build_query(['at' => '2026-07-15T12:00:00Z']), $this->apiToken($this->operator()))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'price_unavailable');

        // Ten days on, every fixture observation has expired: 503 rate_unavailable.
        $this->travel(10)->days();
        $this->prices('', $this->apiToken($this->operator()))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'rate_unavailable');
    }

    /**
     * @return TestResponse<Response>
     */
    private function stations(string $query, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('GET', '/api/v1/stations'.$query, $token), 'GET', '/stations');
    }

    /**
     * @return TestResponse<Response>
     */
    private function prices(string $query, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('GET', '/api/v1/products/prices'.$query, $token), 'GET', '/products/prices');
    }
}
