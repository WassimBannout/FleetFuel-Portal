<?php

namespace App\Http\Controllers\Web;

use App\Enums\CardStatus;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Repositories\ReportRepository;
use App\Services\PriceResolver;
use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin and company-manager landing page (docs/06-UI-SPEC.md): this month's
 * liters and spend next to last month's, quota warnings, open deliveries,
 * the USD/LBP rate in use and the latest purchases.
 *
 * Every number and row is scoped to the user: ->visibleTo($user), or the
 * manager's company for ReportRepository. The page runs a fixed number of
 * queries, however many cards, orders or purchases exist (each list is
 * eager-loaded or a single SQL statement; DashboardTest counts them).
 */
class DashboardController extends Controller
{
    private const OPEN_DELIVERIES = [DeliveryStatus::Pending, DeliveryStatus::Scheduled, DeliveryStatus::OutForDelivery];

    public function __invoke(Request $request, ReportRepository $reports, PriceResolver $prices): View
    {
        /** @var User $user */
        $user = $request->user();
        $now = CarbonImmutable::now();
        $month = BusinessMonth::for($now);
        $previousMonth = CarbonImmutable::parse($month)->subMonthNoOverflow()->format('Y-m-d');

        // Both months in one grouped query; quota_month is the Beirut month of each purchase.
        $monthly = FuelTransaction::query()
            ->visibleTo($user)
            ->whereIn('quota_month', [$month, $previousMonth])
            ->toBase()
            ->select('quota_month')
            ->selectRaw('COUNT(*) AS purchases')
            ->selectRaw('SUM(liters) AS liters')
            ->selectRaw('SUM(amount_lbp) AS amount_lbp')
            ->selectRaw('SUM(amount_usd) AS amount_usd')
            ->groupBy('quota_month')
            ->get()
            ->keyBy(fn (object $row): string => substr((string) $row->quota_month, 0, 10));

        $cardCounts = FuelCard::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $openDeliveries = DeliveryOrder::query()
            ->visibleTo($user)
            ->whereIn('status', self::OPEN_DELIVERIES);

        return view('dashboard', [
            'user' => $user->loadMissing('company'),
            'monthStart' => CarbonImmutable::parse($month),
            'previousMonthStart' => CarbonImmutable::parse($previousMonth),
            'current' => $this->monthTotals($monthly->get($month)),
            'previous' => $this->monthTotals($monthly->get($previousMonth)),
            'companies' => $user->isAdmin() ? Company::query()->visibleTo($user)->count() : null,
            'vehicles' => Vehicle::query()->visibleTo($user)->where('is_active', true)->count(),
            'drivers' => Driver::query()->visibleTo($user)->where('is_active', true)->count(),
            'activeCards' => (int) ($cardCounts[CardStatus::Active->value] ?? 0),
            'blockedCards' => (int) ($cardCounts[CardStatus::Blocked->value] ?? 0),
            // Blocked cards and cards at or over a limit this month (one SQL statement).
            'quotaWarnings' => $reports->quotaExceptions($user->isAdmin() ? null : $user->company_id, $month),
            'openDeliveryCount' => (clone $openDeliveries)->count(),
            'openDeliveries' => $openDeliveries
                ->with('company')
                ->orderBy(DB::raw('COALESCE(scheduled_start_at, preferred_start_at)'))
                ->orderBy('id')
                ->limit(5)
                ->get(),
            'rate' => $prices->findRate($now),
            'now' => $now,
            'recent' => FuelTransaction::query()
                ->visibleTo($user)
                ->with(['company', 'station', 'product', 'fuelCard'])
                ->orderByDesc('transacted_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /**
     * @return array{purchases: int, liters: string, amount_lbp: string, amount_usd: string}
     */
    private function monthTotals(?object $row): array
    {
        return [
            'purchases' => (int) ($row->purchases ?? 0),
            'liters' => (string) ($row->liters ?? '0'),
            'amount_lbp' => (string) ($row->amount_lbp ?? '0'),
            'amount_usd' => (string) ($row->amount_usd ?? '0'),
        ];
    }
}
