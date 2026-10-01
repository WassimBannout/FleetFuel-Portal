<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\Station;
use App\Repositories\ReportRepository;
use App\Support\BusinessMonth;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The report screens (docs/06-UI-SPEC.md, "Reports"). Each one reads
 * ReportRepository with the caller's ReportScope, so a manager only ever
 * sees their own company.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportRepository $reports) {}

    public function consumption(ReportRequest $request): View
    {
        $rows = $this->reports->consumption($request->scope(), $request->groupBy());

        return $this->page($request, 'reports.consumption', [
            'rows' => $rows,
            'groupBy' => $request->groupBy(),
            'totals' => [
                'purchases' => array_sum(array_column($rows, 'purchases')),
                'liters' => $this->sum($rows, 'liters'),
                'amount_lbp' => $this->sum($rows, 'amount_lbp'),
                'amount_usd' => $this->sum($rows, 'amount_usd'),
            ],
            // Choices for the accounting CSV form on this page.
            'stations' => Station::query()->orderBy('name')->pluck('name', 'id'),
            'products' => Product::query()->orderBy('code')->pluck('code', 'code'),
        ]);
    }

    public function topStations(ReportRequest $request): View
    {
        return $this->page($request, 'reports.top-stations', ['rows' => $this->reports->topStations($request->scope())]);
    }

    public function quotaExceptions(ReportRequest $request): View
    {
        $month = BusinessMonth::for(CarbonImmutable::now());

        return $this->page($request, 'reports.quota-exceptions', [
            'rows' => $this->reports->quotaExceptions($request->scope()->companyId, $month),
            'month' => CarbonImmutable::parse($month),
        ]);
    }

    public function anomalies(ReportRequest $request): View
    {
        $scope = $request->scope();

        return $this->page($request, 'reports.anomalies', [
            'overfills' => $this->reports->tankOverfills($scope),
            'rapidFills' => $this->reports->rapidFills($scope),
        ]);
    }

    public function efficiency(ReportRequest $request): View
    {
        return $this->page($request, 'reports.efficiency', ['rows' => $this->reports->efficiency($request->scope())]);
    }

    public function deliverySla(ReportRequest $request): View
    {
        $scope = $request->scope();
        $rows = $this->reports->deliverySla($scope->companyId, $scope->from, $scope->to);
        $delivered = array_sum(array_column($rows, 'delivered'));

        return $this->page($request, 'reports.delivery-sla', [
            'rows' => $rows,
            'delivered' => $delivered,
            'averageHours' => $delivered === 0 ? null : ReportRepository::hours((string) array_sum(array_column($rows, 'total_seconds')), $delivered),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function page(ReportRequest $request, string $view, array $data): View
    {
        [$first, $last] = $request->businessDates();
        $user = $request->actor();
        $companyId = $request->scope()->companyId;

        return view($view, $data + [
            'firstDay' => $first,
            'lastDay' => $last,
            'companies' => $user->isAdmin() ? Company::query()->orderBy('name')->pluck('name', 'id') : null,
            'companyName' => $companyId === null ? null : Company::query()->whereKey($companyId)->value('name'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sum(array $rows, string $column): string
    {
        return (string) (new Collection($rows))
            ->reduce(fn (BigDecimal $total, array $row): BigDecimal => $total->plus((string) $row[$column]), BigDecimal::zero())
            ->toScale(2);
    }
}
