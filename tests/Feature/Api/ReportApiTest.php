<?php

namespace Tests\Feature\Api;

use App\Models\FuelTransaction;
use App\Services\TransactionCsvExport;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CallsApi;
use Tests\Concerns\ChecksOpenApiContract;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\TestCase;

/**
 * GET /api/v1/reports/consumption and GET /api/v1/exports/transactions.csv
 * on the demo seed (current Beirut month: September 2026). Totals are
 * cross-checked against the ledger list for the same filter (T32), and
 * every response against openapi.json.
 */
class ReportApiTest extends TestCase
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

    public function test_consumption_rows_add_up_to_the_ledger_totals_for_the_same_filter(): void
    {
        $token = $this->apiToken($this->admin());
        $ledger = $this->api('GET', '/api/v1/transactions?per_page=1', $token)->assertOk()->json('meta.totals');

        foreach (['company', 'vehicle', 'product'] as $groupBy) {
            $rows = $this->consumption("?group_by={$groupBy}", $token)->assertOk()->json('data');

            $this->assertSame($ledger, [
                'liters' => $this->sum($rows, 'liters'),
                'amount_lbp' => $this->sum($rows, 'amount_lbp'),
                'amount_usd' => $this->sum($rows, 'amount_usd'),
            ], "grouped by {$groupBy}");
        }

        $this->consumption('', $token)->assertExactJson(['data' => [
            ['group_id' => $this->atlas()->id, 'label' => 'Atlas Logistics', 'liters' => '515.00', 'amount_lbp' => '41675000.00', 'amount_usd' => $this->usd($this->atlas()->id)],
            ['group_id' => $this->cedar()->id, 'label' => 'Cedar Catering', 'liters' => '325.00', 'amount_lbp' => '26775000.00', 'amount_usd' => $this->usd($this->cedar()->id)],
        ]]);
        $this->assertSame([null], array_column(array_filter(
            $this->consumption('?group_by=vehicle', $token)->json('data'),
            fn (array $row): bool => $row['label'] === 'No vehicle (card only)',
        ), 'group_id'));
    }

    public function test_consumption_filters_are_scoped_and_allowlisted(): void
    {
        $manager = $this->apiToken($this->atlasManager());
        $admin = $this->apiToken($this->admin());

        $this->assertSame(['Atlas Logistics'], array_column($this->consumption('', $manager)->assertOk()->json('data'), 'label'));
        $this->assertSame(['Cedar Catering'], array_column($this->consumption('?company_id='.$this->cedar()->id, $admin)->assertOk()->json('data'), 'label'));
        $this->assertSame([], $this->consumption('?from=2026-07-01&to=2026-08-01', $admin)->assertOk()->json('data'));

        foreach ([
            'company_id' => ['?company_id='.$this->atlas()->id, $manager],
            'group_by' => ['?group_by=station', $admin],
            'group_by ' => ['?group_by='.rawurlencode('company_id; DROP TABLE fuel_transactions'), $admin],
            'to' => ['?from=2026-01-01&to=2027-01-03', $admin],
            'from' => ['?to=2026-09-01', $admin],
            'per_page' => ['?per_page=5', $admin],
        ] as $field => [$query, $token]) {
            $this->consumption($query, $token)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => [trim($field)]]]);
        }

        $this->consumption('', $this->apiToken($this->operator()))->assertForbidden();
        $this->consumption('', $this->apiToken($this->atlasManager(), ['transactions:read']))->assertForbidden();
        $this->consumption('', null)->assertUnauthorized();
    }

    /** T32: the whole filtered set, in order, with the list's totals; scope and filters exact. */
    public function test_the_csv_holds_every_matching_row_with_the_list_totals(): void
    {
        $token = $this->apiToken($this->atlasManager());
        $response = $this->export('', $token)->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=fleetfuel-transactions-2026-09-01-to-2026-09-30.csv', (string) $response->headers->get('Content-Disposition'));

        [$header, $rows] = $this->parse($response);
        $this->assertSame(TransactionCsvExport::COLUMNS, $header);

        $list = $this->api('GET', '/api/v1/transactions?per_page=100', $token)->assertOk();
        $this->assertCount($list->json('meta.total'), $rows);
        $this->assertSame($list->json('meta.totals'), [
            'liters' => $this->sum($rows, 'liters'),
            'amount_lbp' => $this->sum($rows, 'amount_lbp'),
            'amount_usd' => $this->sum($rows, 'amount_usd'),
        ]);
        $this->assertSame(['Atlas Logistics'], array_values(array_unique(array_column($rows, 'company'))), 'only the manager\'s company');

        // Oldest first, ties by ID; instants in UTC with Z.
        $order = array_map(fn (array $row): array => [$row['transacted_at_utc'], (int) $row['transaction_id']], $rows);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rows[0]['transacted_at_utc']);

        // A stored snapshot, exactly as recorded.
        $first = FuelTransaction::query()->findOrFail((int) $rows[0]['transaction_id']);
        $this->assertSame([$first->liters, $first->unit_price_lbp, $first->amount_lbp, $first->amount_usd, $first->rate_source->value],
            [$rows[0]['liters'], $rows[0]['unit_price_lbp'], $rows[0]['amount_lbp'], $rows[0]['amount_usd'], $rows[0]['rate_source']]);
    }

    public function test_csv_filters_match_the_ledger_list_and_chunking_changes_nothing(): void
    {
        $admin = $this->apiToken($this->admin());
        $filters = '?from=2026-08-01&to=2026-10-01&product_code=DIESEL&station_id='.$this->station('North Demo Station')->id.'&company_id='.$this->atlas()->id;

        [, $rows] = $this->parse($this->export($filters, $admin)->assertOk());
        $list = $this->api('GET', '/api/v1/transactions'.$filters.'&per_page=100', $admin)->assertOk();
        $this->assertSame(array_map(fn (array $row): int => $row['id'], $list->json('data')), array_reverse(array_map(fn (array $row): int => (int) $row['transaction_id'], $rows)));
        $this->assertSame(['North Demo Station'], array_values(array_unique(array_column($rows, 'station'))));

        [, $byCard] = $this->parse($this->export('?card=ff-atlas-h01', $admin)->assertOk());
        $this->assertSame($this->api('GET', '/api/v1/transactions?card=FF-ATLAS-H01', $admin)->json('meta.total'), count($byCard));

        // Reading 3 rows per query gives exactly the same file.
        $whole = $this->export('?from=2026-08-01&to=2026-10-01', $admin)->streamedContent();
        config(['fleetfuel.exports.chunk_size' => 3]);
        $this->assertSame($whole, $this->export('?from=2026-08-01&to=2026-10-01', $admin)->streamedContent());
        $this->assertSame(FuelTransaction::query()->count() + 1, substr_count($whole, "\n"), 'every purchase, no page limit');
    }

    public function test_csv_cells_are_quoted_and_formulas_neutralized(): void
    {
        $this->cedar()->forceFill(['name' => '=HYPERLINK("http://evil.test","Cedar")'])->save();
        $this->station('North Demo Station')->forceFill(['name' => '  @SUM(1+1)'])->save();
        $this->atlas()->forceFill(['name' => 'Atlas "Logistics", Beirut'])->save();

        $response = $this->export('', $this->apiToken($this->admin()))->assertOk();
        $content = $response->streamedContent();
        [, $rows] = $this->parse($response);

        $companies = array_values(array_unique(array_column($rows, 'company')));
        sort($companies);
        $this->assertSame(['\'=HYPERLINK("http://evil.test","Cedar")', 'Atlas "Logistics", Beirut'], $companies);
        $this->assertContains("'  @SUM(1+1)", array_column($rows, 'station'));
        $this->assertStringContainsString('"Atlas ""Logistics"", Beirut"', $content, 'quotes doubled, comma inside a quoted cell');
        $this->assertStringNotContainsString(',=HYPERLINK', $content);
    }

    public function test_csv_access_is_scoped_and_refuses_list_only_parameters(): void
    {
        $this->export('?company_id='.$this->cedar()->id, $this->apiToken($this->atlasManager()))->assertUnprocessable();
        $this->export('?page=2', $this->apiToken($this->admin()))->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['page']]]);
        $this->export('', $this->apiToken($this->operator()))->assertForbidden();
        $this->export('', $this->apiToken($this->atlasManager(), ['reports:read']))->assertForbidden();
        $this->export('', null)->assertUnauthorized();

        [, $cedar] = $this->parse($this->export('', $this->apiToken($this->cedarManager()))->assertOk());
        $this->assertSame(['Cedar Catering'], array_values(array_unique(array_column($cedar, 'company'))));
    }

    /**
     * @return TestResponse<Response>
     */
    private function consumption(string $query, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('GET', '/api/v1/reports/consumption'.$query, $token), 'get', '/reports/consumption');
    }

    /**
     * @return TestResponse<Response>
     */
    private function export(string $query, ?string $token): TestResponse
    {
        return $this->assertMatchesOpenApi($this->api('GET', '/api/v1/exports/transactions.csv'.$query, $token), 'get', '/exports/transactions.csv');
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array{list<string>, list<array<string, string>>}
     */
    private function parse(TestResponse $response): array
    {
        $handle = fopen('php://memory', 'r+');
        assert($handle !== false);
        fwrite($handle, $response->streamedContent());
        rewind($handle);

        $header = fgetcsv($handle, null, ',', '"', '');
        assert(is_array($header));
        $rows = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, array_map(fn (?string $cell): string => (string) $cell, $line));
        }
        fclose($handle);

        return [array_map(fn (?string $cell): string => (string) $cell, $header), $rows];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $column): string
    {
        return (string) array_reduce($rows, fn (BigDecimal $total, array $row): BigDecimal => $total->plus((string) $row[$column]), BigDecimal::zero())->toScale(2);
    }

    private function usd(int $companyId): string
    {
        return $this->sum(FuelTransaction::query()
            ->where('company_id', $companyId)
            ->where('transacted_at', '>=', CarbonImmutable::parse('2026-08-31T21:00:00Z'))
            ->get(['amount_usd'])->toArray(), 'amount_usd');
    }
}
