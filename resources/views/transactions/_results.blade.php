@use('App\Support\Display')

{{--
    Totals, rows and pages of the purchase list. Rendered on the full page and,
    for page scripts, by TransactionController::results(). Expects $purchases
    (paginator), $totals, $summary, $firstDay, $lastDay, $csvUrl (null when the
    user may not export), $showCompany and $showStation.
--}}
<section class="mb-3" aria-labelledby="transaction-totals-heading" data-transaction-totals>
    <h2 id="transaction-totals-heading" class="h6 mb-2">
        {{ $totals['purchases'] === 1 ? 'Totals for the one matching purchase' : 'Totals for all '.number_format($totals['purchases']).' matching purchases' }},
        {{ $firstDay }} to {{ $lastDay }}
        <span class="fw-normal text-body-secondary">(the whole filter, not only this page)</span>
    </h2>
    <div class="row row-cols-2 row-cols-md-4 g-2">
        @foreach ([
            'purchases' => ['Purchases', number_format($totals['purchases'])],
            'liters' => ['Liters', Display::decimal($totals['liters'])],
            'amount_usd' => ['Amount (USD)', Display::decimal($totals['amount_usd'])],
            'amount_lbp' => ['Amount (LBP)', Display::decimal($totals['amount_lbp'])],
        ] as $key => [$label, $shown])
            <div class="col">
                <div class="card stat-card h-100 shadow-sm">
                    <div class="card-body py-2">
                        <div class="small text-body-secondary">{{ $label }}</div>
                        <div class="stat-value" data-total="{{ $key }}" data-value="{{ $totals[$key] }}">{{ $shown }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @if ($csvUrl !== null && $totals['purchases'] > 0)
        <a class="btn btn-sm btn-outline-primary mt-2" href="{{ $csvUrl }}" data-csv-link>
            Download CSV<span class="visually-hidden"> of these {{ $totals['purchases'] }} purchases</span>
        </a>
        <span class="small text-body-secondary ms-2">Same filters, every row, stored amounts.</span>
    @endif
</section>

@if ($totals['purchases'] === 0)
    <div class="alert alert-light border">
        <p class="fw-semibold mb-1">No purchases match these filters.</p>
        <p class="small mb-0">Try a wider date range, or clear the card, station or product filter. Card numbers must be complete.</p>
    </div>
@elseif ($purchases->isEmpty())
    <div class="alert alert-light border">
        <p class="mb-0">This page is past the last page of results. <a href="{{ $purchases->url(1) }}">Go to the first page</a>.</p>
    </div>
@else
    <p class="small text-body-secondary mb-1">{{ $summary }}</p>
    @include('transactions._table', [
        'transactions' => $purchases,
        'showCompany' => $showCompany,
        'showStation' => $showStation,
        'showRateSource' => true,
    ])
    {{ $purchases->onEachSide(1)->links() }}
@endif
