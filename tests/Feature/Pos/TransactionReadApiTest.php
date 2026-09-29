<?php

namespace Tests\Feature\Pos;

use App\Models\FuelTransaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * GET /api/v1/transactions, /transactions/{id} and /cards/{card_no}/balance
 * on the demo seed (as of 2026-09-28T09:00:00Z): every role sees only its
 * scope (T04), totals cover the whole filter, and the balance names no
 * customer. Full OpenAPI parity is M06 (T24).
 */
class TransactionReadApiTest extends TestCase
{
    use BuildsLedgerFixtures;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    /** Current Beirut month (September 2026) as a UTC half-open range. */
    private const MONTH_FROM = '2026-08-31T21:00:00Z';

    private const MONTH_TO = '2026-09-30T21:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_a_manager_lists_only_their_companys_purchases_with_totals_for_the_whole_filter(): void
    {
        $atlas = $this->atlas();
        $expected = $this->ledger(fn (Builder $q) => $q->where('company_id', $atlas->id));
        $this->assertGreaterThan(5, $expected['count']);

        $response = $this->apiGet('/api/v1/transactions?per_page=5', 'manager.atlas@fleetfuel.test')->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertSame([$atlas->id], array_values(array_unique($response->json('data.*.company_id'))));
        $response->assertJsonPath('meta.total', $expected['count'])
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', (int) ceil($expected['count'] / 5))
            ->assertJsonPath('meta.totals', $expected['totals'])
            ->assertJsonPath('links.prev', null);
        $this->assertStringContainsString('page=2', (string) $response->json('links.next'));
        $this->assertStringContainsString('per_page=5', (string) $response->json('links.next'));

        // Newest first.
        $times = $response->json('data.*.transacted_at');
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times);

        $this->assertSame(
            ['id', 'external_ref', 'station_id', 'company_id', 'card_no', 'product_code', 'liters', 'unit_price_lbp', 'amount_lbp', 'amount_usd', 'rate_lbp_per_usd', 'rate_source', 'rate_effective_at', 'transacted_at', 'quota_month', 'odometer_km'],
            array_keys($response->json('data.0')),
        );
    }

    public function test_an_operator_lists_only_their_stations_purchases(): void
    {
        $station = $this->station('Harbor Demo Station');
        $expected = $this->ledger(fn (Builder $q) => $q->where('station_id', $station->id));

        $response = $this->apiGet('/api/v1/transactions?per_page=100', 'operator.beirut@fleetfuel.test')->assertOk();

        $this->assertSame([$station->id], array_values(array_unique($response->json('data.*.station_id'))));
        $response->assertJsonPath('meta.total', $expected['count'])->assertJsonPath('meta.totals', $expected['totals']);
    }

    public function test_only_an_admin_may_filter_by_company(): void
    {
        $cedar = $this->cedar();

        $this->apiGet("/api/v1/transactions?company_id={$cedar->id}", 'admin@fleetfuel.test')->assertOk()
            ->assertJsonPath('meta.total', $this->ledger(fn (Builder $q) => $q->where('company_id', $cedar->id))['count']);

        // Refused for a manager even when it names their own company.
        $atlas = $this->atlas();
        $this->apiGet("/api/v1/transactions?company_id={$atlas->id}", 'manager.atlas@fleetfuel.test')
            ->assertUnprocessable()->assertJsonValidationErrorFor('company_id', 'error.details');
        $this->apiGet("/api/v1/transactions?company_id={$atlas->id}", 'operator.beirut@fleetfuel.test')->assertUnprocessable();
    }

    public function test_card_product_station_and_date_filters_narrow_the_list_and_its_totals(): void
    {
        $card = $this->card('FF-ATLAS-H01');
        $this->apiGet('/api/v1/transactions?card=ff-atlas-h01', 'admin@fleetfuel.test')->assertOk()
            ->assertJsonPath('meta.total', $this->ledger(fn (Builder $q) => $q->where('fuel_card_id', $card->id))['count']);

        $station = $this->station('North Demo Station');
        $this->apiGet("/api/v1/transactions?station_id={$station->id}&product_code=DIESEL", 'admin@fleetfuel.test')->assertOk()
            ->assertJsonPath('meta.totals', $this->ledger(fn (Builder $q) => $q->where('station_id', $station->id)
                ->whereHas('product', fn (Builder $p) => $p->where('code', 'DIESEL')))['totals']);

        // August in Beirut: from 1 August inclusive to 1 September exclusive.
        $august = $this->ledger(fn (Builder $q) => $q, '2026-07-31T21:00:00Z', self::MONTH_FROM);
        $this->assertGreaterThan(0, $august['count']);
        $this->apiGet('/api/v1/transactions?from=2026-08-01&to=2026-09-01&per_page=100', 'admin@fleetfuel.test')->assertOk()
            ->assertJsonPath('meta.total', $august['count'])
            ->assertJsonPath('meta.totals', $august['totals']);
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_refused(array $query, string $field): void
    {
        $this->apiGet('/api/v1/transactions?'.http_build_query($query), 'admin@fleetfuel.test')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrorFor($field, 'error.details');
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'per_page zero' => [['per_page' => '0'], 'per_page'],
            'per_page above 100' => [['per_page' => '101'], 'per_page'],
            'per_page text' => [['per_page' => 'all'], 'per_page'],
            'from without to' => [['from' => '2026-09-01'], 'to'],
            'to before from' => [['from' => '2026-09-10', 'to' => '2026-09-01'], 'to'],
            'range over 366 days' => [['from' => '2025-01-01', 'to' => '2026-01-03'], 'to'],
            'date in another format' => [['from' => '01/09/2026', 'to' => '2026-09-10'], 'from'],
            'unknown product' => [['product_code' => 'KEROSENE'], 'product_code'],
            'unknown parameter' => [['sort' => 'amount_usd'], 'sort'],
        ];
    }

    public function test_detail_is_scoped_like_the_list(): void
    {
        $atlasPurchase = FuelTransaction::query()->where('company_id', $this->atlas()->id)->firstOrFail();
        $cedarPurchase = FuelTransaction::query()->where('company_id', $this->cedar()->id)->firstOrFail();

        $this->apiGet("/api/v1/transactions/{$atlasPurchase->id}", 'manager.atlas@fleetfuel.test')->assertOk()
            ->assertJsonPath('data.id', $atlasPurchase->id)
            ->assertJsonPath('data.amount_usd', $atlasPurchase->amount_usd);

        // Another company's purchase looks exactly like a missing one.
        $this->apiGet("/api/v1/transactions/{$cedarPurchase->id}", 'manager.atlas@fleetfuel.test')
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->apiGet('/api/v1/transactions/999999', 'manager.atlas@fleetfuel.test')->assertNotFound();

        $otherStation = FuelTransaction::query()->where('station_id', '!=', $this->station('Harbor Demo Station')->id)->firstOrFail();
        $this->apiGet("/api/v1/transactions/{$otherStation->id}", 'operator.beirut@fleetfuel.test')->assertNotFound();
    }

    public function test_the_balance_uses_current_limits_and_names_no_customer(): void
    {
        // The quota-cut scenario: 250 L used this month, limit lowered to 200 L.
        $card = $this->card('FF-ATLAS-H02');

        $response = $this->apiGet('/api/v1/cards/ff-atlas-h02/balance', 'manager.atlas@fleetfuel.test')->assertOk();

        $response->assertExactJson(['data' => [
            'card_no' => 'FF-ATLAS-H02',
            'month' => '2026-09',
            'status' => 'active',
            'monthly_limit_l' => '200.00',
            'monthly_limit_usd' => $card->monthly_limit_usd,
            'used_l' => '250.00',
            'used_usd' => $response->json('data.used_usd'),
            'remaining_l' => '0.00',
            'remaining_usd' => $response->json('data.remaining_usd'),
            'over_quota' => true,
        ]]);
    }

    public function test_the_balance_is_scoped_for_managers_but_open_to_operators_for_pos_lookups(): void
    {
        $this->apiGet('/api/v1/cards/FF-CEDAR-001/balance', 'manager.atlas@fleetfuel.test')
            ->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $this->apiGet('/api/v1/cards/FF-CEDAR-001/balance', 'operator.beirut@fleetfuel.test')->assertOk()
            ->assertJsonPath('data.used_l', '0.00')
            ->assertJsonPath('data.over_quota', false);

        $this->apiGet('/api/v1/cards/FF-CEDAR-001/balance', 'admin@fleetfuel.test')->assertOk();
        $this->apiGet('/api/v1/cards/FF-NO-SUCH-CARD/balance', 'admin@fleetfuel.test')->assertNotFound();
    }

    public function test_the_balance_month_is_the_current_or_previous_beirut_month(): void
    {
        $card = $this->card('FF-ATLAS-H01');
        $august = $this->ledger(fn (Builder $q) => $q->where('fuel_card_id', $card->id), '2026-07-31T21:00:00Z', self::MONTH_FROM);

        $this->apiGet('/api/v1/cards/FF-ATLAS-H01/balance?month=2026-08', 'admin@fleetfuel.test')->assertOk()
            ->assertJsonPath('data.month', '2026-08')
            ->assertJsonPath('data.used_l', $august['totals']['liters']);

        foreach (['2026-07', '2026-10', '2026-9', 'september'] as $month) {
            $this->apiGet("/api/v1/cards/FF-ATLAS-H01/balance?month={$month}", 'admin@fleetfuel.test')
                ->assertUnprocessable()->assertJsonValidationErrorFor('month', 'error.details');
        }

        $this->apiGet('/api/v1/cards/FF-ATLAS-H01/balance?company_id=1', 'admin@fleetfuel.test')->assertUnprocessable();
    }

    /** T23 (detection): the read-only reconciliation command. */
    public function test_reconciliation_passes_on_a_consistent_ledger_and_reports_a_tampered_counter(): void
    {
        $this->artisan('usage:reconcile')
            ->expectsOutputToContain('All monthly usage counters match the ledger.')
            ->assertSuccessful();

        $card = $this->card('FF-ATLAS-H01');
        $counter = fn () => DB::table('card_monthly_usage')->where('fuel_card_id', $card->id)->where('month_start', '2026-09-01');
        $counter()->update(['used_l' => '1.00']);

        $this->artisan('usage:reconcile')
            ->expectsOutputToContain('1 counter(s) differ from the ledger')
            ->doesntExpectOutputToContain('FF-ATLAS-H01')
            ->assertFailed();

        // Read-only: the tampered value is still there for someone to investigate.
        $this->assertSame('1.00', (string) $counter()->value('used_l'));
    }

    /**
     * A GET with a fresh real token for the demo account.
     *
     * @return TestResponse<Response>
     */
    private function apiGet(string $uri, string $email): TestResponse
    {
        $user = User::query()->where('email', $email)->firstOrFail();
        $token = $user->createToken('phpunit', $user->role->tokenAbilities())->plainTextToken;

        $this->startNewRequestCycle();

        return $this->withToken($token)->getJson($uri);
    }

    /**
     * Count and exact totals of the ledger rows in a UTC range, computed
     * independently of the API.
     *
     * @param  callable(Builder<FuelTransaction>): Builder<FuelTransaction>  $scope
     * @return array{count: int, totals: array{liters: string, amount_lbp: string, amount_usd: string}}
     */
    private function ledger(callable $scope, string $from = self::MONTH_FROM, string $to = self::MONTH_TO): array
    {
        $rows = $scope(FuelTransaction::query())
            ->where('transacted_at', '>=', CarbonImmutable::parse($from))
            ->where('transacted_at', '<', CarbonImmutable::parse($to))
            ->get(['liters', 'amount_lbp', 'amount_usd']);

        $sum = fn (string $column): string => (string) $rows->reduce(
            fn (BigDecimal $carry, FuelTransaction $row) => $carry->plus($row->{$column}),
            BigDecimal::zero(),
        )->toScale(2);

        return [
            'count' => $rows->count(),
            'totals' => ['liters' => $sum('liters'), 'amount_lbp' => $sum('amount_lbp'), 'amount_usd' => $sum('amount_usd')],
        ];
    }
}
