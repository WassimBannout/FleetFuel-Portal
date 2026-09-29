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
use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin and company-manager landing page. Every number and row comes from a
 * query scoped with ->visibleTo($user): a manager's totals include their
 * own company only.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $month = BusinessMonth::for(CarbonImmutable::now());

        $monthTotals = FuelTransaction::query()
            ->visibleTo($user)
            ->where('quota_month', $month)
            ->toBase()
            ->selectRaw('COUNT(*) AS purchases')
            ->selectRaw('COALESCE(SUM(liters), 0) AS liters')
            ->selectRaw('COALESCE(SUM(amount_lbp), 0) AS amount_lbp')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) AS amount_usd')
            ->first();

        $cardCounts = FuelCard::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('dashboard', [
            'user' => $user->loadMissing('company'),
            'monthStart' => CarbonImmutable::parse($month),
            'monthTotals' => $monthTotals,
            'companies' => $user->isAdmin() ? Company::query()->visibleTo($user)->count() : null,
            'vehicles' => Vehicle::query()->visibleTo($user)->where('is_active', true)->count(),
            'drivers' => Driver::query()->visibleTo($user)->where('is_active', true)->count(),
            'activeCards' => (int) ($cardCounts[CardStatus::Active->value] ?? 0),
            'blockedCards' => (int) ($cardCounts[CardStatus::Blocked->value] ?? 0),
            'openDeliveries' => DeliveryOrder::query()
                ->visibleTo($user)
                ->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Scheduled, DeliveryStatus::OutForDelivery])
                ->count(),
            'recent' => FuelTransaction::query()
                ->visibleTo($user)
                ->with(['company', 'station', 'product', 'fuelCard'])
                ->orderByDesc('transacted_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }
}
