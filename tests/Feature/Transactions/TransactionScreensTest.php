<?php

namespace Tests\Feature\Transactions;

use App\Models\Company;
use App\Models\FuelTransaction;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CountsQueries;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * The purchase list screen and its AJAX results (T34, plus T04 on this new
 * surface), on the demo seed: 32 purchases in August and September 2026.
 * Expected figures are computed here with Eloquent and brick/math, not with
 * the controller's SQL.
 */
class TransactionScreensTest extends TestCase
{
    use BuildsLedgerFixtures;
    use CountsQueries;
    use RefreshDatabase;
    use SignsInDemoAccounts;

    private const RANGE = '?from=2026-08-01&to=2026-10-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
    }

    public function test_each_role_sees_only_its_scope_with_matching_filters(): void
    {
        // Admin: every company, filters for company and station, and the CSV link.
        $page = $this->actingAs($this->admin())->get('/transactions'.self::RANGE)->assertOk()
            ->assertSee('Atlas Logistics')->assertSee('Cedar Catering')
            ->assertSee('name="company_id"', false)->assertSee('name="station_id"', false)->assertSee('data-csv-link', false);
        $this->assertSame($this->expectedTotals(FuelTransaction::query()), $page->viewData('totals'));
        $this->assertSame(32, $page->viewData('totals')['purchases']);

        // Manager: their company only, no company filter.
        $atlas = $this->atlas();
        $page = $this->actingAs($this->atlasManager())->get('/transactions'.self::RANGE)->assertOk()
            ->assertDontSee('Cedar Catering')->assertDontSee('name="company_id"', false)->assertSee('data-csv-link', false);
        $this->assertSame($this->expectedTotals(FuelTransaction::query()->where('company_id', $atlas->id)), $page->viewData('totals'));
        foreach ($page->viewData('purchases') as $purchase) {
            $this->assertSame($atlas->id, $purchase->company_id);
        }

        // Operator: their station only, no station or company filter, no export.
        $station = $this->station('Harbor Demo Station');
        $page = $this->actingAs($this->operator())->get('/transactions'.self::RANGE)->assertOk()
            ->assertDontSee('name="station_id"', false)->assertDontSee('name="company_id"', false)->assertDontSee('data-csv-link', false);
        $this->assertSame($this->expectedTotals(FuelTransaction::query()->where('station_id', $station->id)), $page->viewData('totals'));
        $this->assertGreaterThan(0, $page->viewData('totals')['purchases']);
        foreach ($page->viewData('purchases') as $purchase) {
            $this->assertSame($station->id, $purchase->station_id);
        }
    }

    /** T04: filters typed into the URL can narrow a scope, never widen it. */
    public function test_tampered_filters_never_widen_a_scope(): void
    {
        $this->actingAs($this->atlasManager());

        $this->results(self::RANGE.'&company_id='.$this->cedar()->id)
            ->assertStatus(422)->assertJsonValidationErrors(['company_id']);

        $north = $this->station('North Demo Station');
        $json = $this->results(self::RANGE.'&station_id='.$north->id)->assertOk()->json();
        $this->assertStringNotContainsString('Cedar', $json['html']);
        $expected = FuelTransaction::query()->where('company_id', $this->atlas()->id)->where('station_id', $north->id)->count();
        $this->assertGreaterThan(0, $expected);
        $this->assertStringContainsString("of {$expected} purchases", $json['summary']);

        $this->assertSame('No purchases match these filters.', $this->results(self::RANGE.'&card=FF-CEDAR-H01')->assertOk()->json('summary'));

        $this->actingAs($this->operator());
        $this->assertSame('No purchases match these filters.', $this->results(self::RANGE.'&station_id='.$north->id)->assertOk()->json('summary'));
    }

    /** T34 "totals agree with export": the totals cover every page, and the CSV link holds exactly those rows. */
    public function test_totals_cover_the_whole_filter_and_equal_the_csv_of_the_same_filter(): void
    {
        $this->actingAs($this->admin());
        $harbor = $this->station('Harbor Demo Station')->id;

        foreach ([self::RANGE, self::RANGE.'&product_code=DIESEL', self::RANGE."&station_id={$harbor}&card=ff-atlas-h01"] as $query) {
            $first = $this->get('/transactions'.$query)->assertOk();
            $totals = $first->viewData('totals');
            $rowsOnPages = $first->viewData('purchases')->count();

            for ($page = 2; $page <= $first->viewData('purchases')->lastPage(); $page++) {
                $rowsOnPages += $this->get('/transactions'.$query.'&page='.$page)->assertOk()->viewData('purchases')->count();
            }
            $this->assertGreaterThan(0, $totals['purchases'], $query);
            $this->assertSame($totals['purchases'], $rowsOnPages, "every row of {$query} is on some page");

            $csv = $this->csvRows($this->get($first->viewData('csvUrl'))->assertOk());
            $this->assertCount($totals['purchases'], $csv, "CSV rows for {$query}");
            foreach (['liters', 'amount_lbp', 'amount_usd'] as $column) {
                $this->assertSame($totals[$column], $this->sum($csv, $column), "{$column} for {$query}");
            }
        }

        // 32 purchases need two pages of 25: the second page has the other 7, the totals stay the same.
        $second = $this->get('/transactions'.self::RANGE.'&page=2')->assertOk();
        $this->assertCount(7, $second->viewData('purchases'));
        $this->assertSame(32, $second->viewData('totals')['purchases']);
    }

    public function test_the_results_endpoint_returns_escaped_html_a_summary_and_the_address(): void
    {
        Company::query()->whereKey($this->atlas()->id)->update(['name' => '<script>alert("x")</script> Atlas']);
        $this->actingAs($this->admin());

        $json = $this->results(self::RANGE.'&page=2&card=&product_code=')->assertOk()
            ->assertJsonStructure(['html', 'summary', 'url', 'from', 'to'])->json();

        $this->assertSame('Showing 26–32 of 32 purchases.', $json['summary']);
        // Relative, without empty filters: the address the browser history stores.
        $this->assertSame('/transactions?from=2026-08-01&to=2026-10-01&page=2', $json['url']);
        $this->assertSame(['2026-08-01', '2026-10-01'], [$json['from'], $json['to']]);
        // Pages link to the full page, so they work without JavaScript too.
        $this->assertStringContainsString('href="http://localhost/transactions?from=2026-08-01&amp;to=2026-10-01&amp;page=1"', $json['html']);
        $this->assertStringNotContainsString('/transactions/results', $json['html']);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; Atlas', $json['html']);
        $this->assertStringNotContainsString('<script>alert', $json['html']);

        // Empty dates mean the current Beirut month, as on the API list.
        $json = $this->results('')->assertOk()->json();
        $this->assertSame(['2026-09-01', '2026-10-01'], [$json['from'], $json['to']]);
        $this->assertSame('/transactions?from=2026-09-01&to=2026-10-01', $json['url']);
    }

    public function test_invalid_filters_get_field_errors_and_never_stale_results(): void
    {
        $this->actingAs($this->admin());

        $this->results('?from=2026-09-10&to=2026-09-01')->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->results('?from=2025-01-01&to=2026-09-01')->assertStatus(422)
            ->assertJsonValidationErrors(['to' => 'The date range may cover at most 366 days.']);
        $this->results('?product_code=KEROSENE&card=FF%20ATLAS')->assertStatus(422)->assertJsonValidationErrors(['product_code', 'card']);

        // Without JavaScript: back to the unfiltered list, with the message and the typed value.
        $this->get('/transactions?from=2026-09-10&to=2026-09-01')->assertRedirect('/transactions');
        $this->followingRedirects()->get('/transactions?from=2026-09-10&to=2026-09-01')->assertOk()
            ->assertSee('The to field must be a date after from.')
            ->assertSee('aria-invalid="true" aria-describedby="filter-to-error"', false)
            ->assertSee('value="2026-09-01"', false);
    }

    public function test_a_signed_out_browser_gets_401_from_the_results_and_the_sign_in_page_otherwise(): void
    {
        $this->results(self::RANGE)->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        $this->get('/transactions')->assertRedirect('/login');
    }

    /** N+1 check: the number of queries does not grow with the rows shown. */
    public function test_the_list_runs_a_fixed_number_of_queries(): void
    {
        $admin = $this->admin();
        $before = $this->queriesFor($admin, '/transactions/results'.self::RANGE);

        $this->addCardOnlyPurchases(30);
        $after = $this->queriesFor($admin, '/transactions/results'.self::RANGE);

        $this->assertSame($before, $after, 'queries with 32 and with 62 purchases');
        // One aggregate (totals + count), one page of rows, one each for company, station, product and card.
        $this->assertSame(6, $after);
    }

    /**
     * @param  Builder<FuelTransaction>  $query
     * @return array{purchases: int, liters: string, amount_lbp: string, amount_usd: string}
     */
    private function expectedTotals(Builder $query): array
    {
        $rows = $query->where('transacted_at', '>=', '2026-07-31 21:00:00')->where('transacted_at', '<', '2026-09-30 21:00:00')
            ->get(['liters', 'amount_lbp', 'amount_usd'])->toArray();

        return [
            'purchases' => count($rows),
            'liters' => $this->sum($rows, 'liters'),
            'amount_lbp' => $this->sum($rows, 'amount_lbp'),
            'amount_usd' => $this->sum($rows, 'amount_usd'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $column): string
    {
        return (string) array_reduce($rows, fn (BigDecimal $total, array $row): BigDecimal => $total->plus((string) $row[$column]), BigDecimal::zero())->toScale(2);
    }

    /**
     * @return TestResponse<Response>
     */
    private function results(string $query): TestResponse
    {
        return $this->getJson('/transactions/results'.$query);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, string>>
     */
    private function csvRows(TestResponse $response): array
    {
        $lines = array_map(
            fn (string $line): array => array_map(fn (?string $cell): string => (string) $cell, str_getcsv($line, ',', '"', '')),
            array_values(array_filter(explode("\n", (string) $response->streamedContent()))),
        );
        $header = array_shift($lines);
        assert(is_array($header));

        return array_map(fn (array $line): array => array_combine($header, $line), $lines);
    }
}
