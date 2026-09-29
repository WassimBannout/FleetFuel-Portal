<?php

namespace App\Http\Controllers\Web;

use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\FiltersLists;
use App\Http\Requests\Reference\StoreExchangeRateOverrideRequest;
use App\Models\ExchangeRate;
use App\Models\IntegrationSyncState;
use App\Services\ExchangeRateService;
use App\Services\PriceResolver;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admin integration status for USD/LBP: the rate in effect, recent
 * observations, the last sync result and the manual override form.
 *
 * It only reads stored rows. Page requests never call the provider; the
 * scheduler or `php artisan rates:sync` does.
 */
class ExchangeRateController extends Controller
{
    use FiltersLists;

    public function index(PriceResolver $resolver): View
    {
        Gate::authorize('viewAny', ExchangeRate::class);

        $mode = RateMode::current();
        $now = CarbonImmutable::now();

        return view('integrations.exchange-rates', [
            'mode' => $mode,
            'now' => $now,
            'current' => $resolver->findRate($now),
            'state' => IntegrationSyncState::query()->where('name', $mode->syncStateName())->first(),
            'observations' => ExchangeRate::query()->usdLbp()->where('source', $mode->source())
                ->orderByDesc('effective_at')->limit(10)->get(),
            'overrides' => ExchangeRate::query()->usdLbp()->where('source', RateSource::Manual)
                ->with('creator')->orderByDesc('effective_at')->limit(10)->get(),
        ]);
    }

    public function store(StoreExchangeRateOverrideRequest $request, ExchangeRateService $rates): RedirectResponse
    {
        $override = $rates->createOverride(
            $this->actor($request),
            $request->rate(),
            $request->reason(),
            $request->validForHours(),
            $request->startsAt(),
        );

        return redirect()->route('integrations.exchange-rates')->with(
            'status',
            'Override of '.Display::decimal($override->rate, 8).' LBP per USD saved, valid from '
                .Display::businessTime($override->effective_at).' to '.Display::businessTime($override->expires_at).' (Beirut time).',
        );
    }
}
