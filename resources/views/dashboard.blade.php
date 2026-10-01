@extends('layouts.app')

@use('App\Enums\RateSource')
@use('App\Support\Display')
@use('App\Support\Redact')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
        <h1 class="h3 mb-0">Dashboard</h1>
        <span class="text-body-secondary">
            {{ $user->isAdmin() ? 'All companies' : $user->company?->name }}
            · {{ $monthStart->format('F Y') }} (Beirut time)
        </span>
    </div>

    <section aria-labelledby="month-heading" class="mb-4">
        <h2 id="month-heading" class="visually-hidden">This month</h2>
        <div class="row row-cols-2 row-cols-xl-4 g-2 g-md-3">
            @foreach ([
                'purchases' => ['Purchases this month', fn ($totals) => number_format($totals['purchases'])],
                'liters' => ['Liters this month', fn ($totals) => Display::decimal($totals['liters'])],
                'amount_usd' => ['Spend this month (USD)', fn ($totals) => Display::decimal($totals['amount_usd'])],
                'amount_lbp' => ['Spend this month (LBP)', fn ($totals) => Display::decimal($totals['amount_lbp'])],
            ] as $key => [$label, $format])
                <div class="col">
                    <div class="card stat-card h-100 shadow-sm">
                        <div class="card-body">
                            <div class="small text-body-secondary">{{ $label }}</div>
                            <div class="stat-value" data-month-total="{{ $key }}">{{ $format($current) }}</div>
                            <div class="small text-body-secondary">{{ $previousMonthStart->format('F') }}: {{ $format($previous) }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="small text-body-secondary mt-2 mb-0">
            Amounts are the LBP and USD stored with each purchase, at its own price and exchange rate.
            <a href="{{ route('transactions.index') }}">Open the transaction list</a> for any period.
        </p>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <section class="card h-100 shadow-sm" aria-labelledby="quota-heading">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
                        <h2 id="quota-heading" class="h5 mb-0">Quota warnings</h2>
                        @if ($quotaWarnings !== [])
                            <span class="badge text-bg-warning">{{ count($quotaWarnings) }} {{ count($quotaWarnings) === 1 ? 'card needs' : 'cards need' }} attention</span>
                        @endif
                    </div>
                    @if ($quotaWarnings === [])
                        <p class="text-body-secondary mb-0">No card is blocked or out of quota this month.</p>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach (array_slice($quotaWarnings, 0, 5) as $warning)
                                <li class="list-group-item px-0">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <span>
                                            <a class="font-monospace" href="{{ route('cards.show', $warning['card_id']) }}">{{ Redact::cardNumber($warning['card_no']) }}</a>
                                            <span class="text-body-secondary">· {{ $warning['vehicle_plate'] ?? 'No vehicle' }}</span>
                                            @if ($user->isAdmin())
                                                <span class="text-body-secondary">· {{ $warning['company'] }}</span>
                                            @endif
                                        </span>
                                        @if ($warning['status'] === 'blocked')
                                            <span class="badge text-bg-danger">Blocked</span>
                                        @else
                                            <span class="badge text-bg-warning">Out of quota</span>
                                        @endif
                                    </div>
                                    <div class="small">{{ implode(' ', $warning['reasons']) }}</div>
                                </li>
                            @endforeach
                        </ul>
                        <a class="small" href="{{ route('reports.quota-exceptions') }}">
                            {{ count($quotaWarnings) > 5 ? 'See all '.count($quotaWarnings).' in the quota exceptions report' : 'Open the quota exceptions report' }}
                        </a>
                    @endif
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="card h-100 shadow-sm" aria-labelledby="deliveries-heading">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
                        <h2 id="deliveries-heading" class="h5 mb-0">Open deliveries</h2>
                        <span class="text-body-secondary small">{{ $openDeliveryCount }} open</span>
                    </div>
                    @if ($openDeliveries->isEmpty())
                        <p class="text-body-secondary">No delivery is pending, scheduled or on the way.</p>
                    @else
                        <ul class="list-group list-group-flush mb-2">
                            @foreach ($openDeliveries as $order)
                                <li class="list-group-item px-0">
                                    <div class="d-flex flex-wrap justify-content-between gap-2">
                                        <a href="{{ route('deliveries.show', $order) }}">Order #{{ $order->id }}</a>
                                        @include('partials.delivery-status-badge', ['status' => $order->status])
                                    </div>
                                    <div class="small text-body-secondary">
                                        @if ($user->isAdmin()){{ $order->company->name }} · @endif{{ $order->governorate }} · {{ Display::decimal($order->liters) }} L
                                        · {{ $order->scheduled_start_at ? 'scheduled '.Display::businessTime($order->scheduled_start_at) : 'preferred from '.Display::businessTime($order->preferred_start_at) }}
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a class="small" href="{{ route('deliveries.index') }}">All deliveries</a>
                    @can('create', App\Models\DeliveryOrder::class)
                        · <a class="small" href="{{ route('deliveries.create') }}">Request a delivery</a>
                    @endcan
                </div>
            </section>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <section class="card h-100 shadow-sm" aria-labelledby="fleet-heading">
                <div class="card-body">
                    <h2 id="fleet-heading" class="h5">Fleet</h2>
                    <dl class="row row-cols-2 row-cols-md-3 g-2 mb-0">
                        @foreach (array_filter([
                            'Companies' => $companies,
                            'Active vehicles' => $vehicles,
                            'Active drivers' => $drivers,
                            'Active cards' => $activeCards,
                            'Blocked cards' => $blockedCards,
                        ], fn ($value) => $value !== null) as $label => $value)
                            <div class="col">
                                <dt class="small fw-normal text-body-secondary">{{ $label }}</dt>
                                <dd class="fs-5 fw-semibold mb-0">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="card h-100 shadow-sm" aria-labelledby="rate-heading">
                <div class="card-body">
                    <h2 id="rate-heading" class="h5">USD/LBP rate in use</h2>
                    @if ($rate)
                        <p class="fs-5 fw-semibold mb-1">{{ Display::decimal($rate->rate, 8) }} LBP per USD</p>
                        <p class="mb-1">@include('partials.rate-source-badge', ['source' => $rate->source])</p>
                        <p class="small text-body-secondary mb-1">
                            Effective {{ Display::businessTime($rate->effective_at) }}, valid until {{ Display::businessTime($rate->expires_at) }} (Beirut time).
                            New purchases store this rate; earlier purchases keep their own.
                        </p>
                        @if ($rate->source === RateSource::Provider)
                            @include('partials.rate-attribution')
                        @endif
                    @else
                        <div class="alert alert-warning mb-1" role="status">
                            No valid USD/LBP rate right now. POS purchases are refused until a rate is available (never converted at a guessed rate).
                        </div>
                    @endif
                    @can('viewAny', App\Models\ExchangeRate::class)
                        <a class="small" href="{{ route('integrations.exchange-rates') }}">Exchange rate status and overrides</a>
                    @endcan
                </div>
            </section>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
        <h2 class="h5">Latest purchases</h2>
        <a class="small" href="{{ route('transactions.index') }}">All transactions</a>
    </div>
    @include('transactions._table', [
        'transactions' => $recent,
        'showCompany' => $user->isAdmin(),
        'showStation' => true,
        'showRateSource' => true,
    ])
@endsection
