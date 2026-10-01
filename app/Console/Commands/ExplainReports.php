<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Repositories\ReportRepository;
use App\Services\TransactionCsvExport;
use App\Support\BusinessMonth;
use App\Support\ReportScope;
use App\Support\TransactionFilters;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Prints MySQL's plan for each report query and the CSV export's chunk
 * query, exactly as the application builds them: the queries are captured
 * with DB::pretend() and then explained with their bindings. Read-only;
 * --analyze runs EXPLAIN ANALYZE, which executes the SELECTs to measure
 * them. docs/REPORT-QUERY-PLANS.md records a run on representative data.
 */
class ExplainReports extends Command
{
    protected $signature = 'reports:explain
        {--from= : first Beirut date (Y-m-d); default: the current month}
        {--to= : Beirut date after the last one (Y-m-d)}
        {--company= : one company ID, as a manager sees it; default: all companies}
        {--analyze : EXPLAIN ANALYZE (runs the read-only queries and shows real row counts and times)}';

    protected $description = 'Print the MySQL query plans of the report and CSV export queries (read-only)';

    public function handle(ReportRepository $reports, TransactionCsvExport $export): int
    {
        $timezone = (string) config('fleetfuel.business_timezone');
        $month = BusinessMonth::for(CarbonImmutable::now());
        $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from'), $timezone)->startOfDay()->utc() : BusinessMonth::startUtc($month);
        $to = $this->option('to') ? CarbonImmutable::parse((string) $this->option('to'), $timezone)->startOfDay()->utc() : BusinessMonth::startUtc(CarbonImmutable::parse($month)->addMonthNoOverflow()->format('Y-m-d'));
        $company = $this->option('company') === null ? null : (int) $this->option('company');
        $scope = ReportScope::forConsole($from, $to, $company);

        // The CSV export reads like an admin; a company filter narrows it.
        $reader = (new User)->forceFill(['role' => UserRole::Admin]);
        $filters = new TransactionFilters($from, $to, companyId: $company);

        $queries = [
            'consumption by company' => fn () => $reports->consumption($scope, 'company'),
            'consumption by vehicle' => fn () => $reports->consumption($scope, 'vehicle'),
            'consumption by product' => fn () => $reports->consumption($scope, 'product'),
            'top stations' => fn () => $reports->topStations($scope),
            'quota exceptions' => fn () => $reports->quotaExceptions($company, BusinessMonth::for($from)),
            'tank overfills' => fn () => $reports->tankOverfills($scope),
            'rapid fills' => fn () => $reports->rapidFills($scope),
            'efficiency estimate' => fn () => $reports->efficiency($scope),
            'delivery SLA' => fn () => $reports->deliverySla($company, $from, $to),
            'CSV export, first chunk' => fn () => iterator_to_array($export->rows($reader, $filters)),
        ];

        $this->line("Range [{$from->toIso8601ZuluString()}, {$to->toIso8601ZuluString()}), company ".($company ?? 'all').', '.DB::table('fuel_transactions')->count().' ledger rows.');

        foreach ($queries as $label => $run) {
            foreach ($this->capture($run) as $query) {
                $this->newLine();
                $this->info("== {$label}");
                $prefix = $this->option('analyze') ? 'EXPLAIN ANALYZE ' : 'EXPLAIN FORMAT=TREE ';

                // MySQL takes no placeholders in EXPLAIN, so the values are
                // inlined with the connection's own escaping.
                $connection = DB::connection();
                $sql = $connection->getQueryGrammar()->substituteBindingsIntoRawSql($query['query'], $connection->prepareBindings($query['bindings']));

                foreach (DB::select($prefix.$sql) as $row) {
                    $this->line((string) (((array) $row)['EXPLAIN'] ?? json_encode($row)));
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * The SQL a callback would run, without running it.
     *
     * @return list<array{query: string, bindings: array<int, mixed>}>
     */
    private function capture(Closure $run): array
    {
        return array_map(
            fn (array $query): array => ['query' => (string) $query['query'], 'bindings' => (array) $query['bindings']],
            DB::pretend($run),
        );
    }
}
