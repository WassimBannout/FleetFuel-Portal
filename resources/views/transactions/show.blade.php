@extends('layouts.app')

@use('App\Support\Display')
@use('App\Support\Redact')

@section('title', 'Purchase '.$transaction->id)

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
        <h1 class="h3 mb-0">Purchase {{ $transaction->id }}</h1>
        <span class="text-body-secondary">POS reference <span class="font-monospace">{{ $transaction->external_ref }}</span></span>
    </div>

    @if ($transaction->exceedsTankCapacity())
        <div class="alert alert-warning" role="alert">
            Anomaly: {{ Display::decimal($transaction->liters) }} L is more than the vehicle's recorded
            tank capacity of {{ Display::decimal($transaction->tank_capacity_l) }} L.
        </div>
    @endif

    <p class="text-body-secondary">
        Accepted purchases are never edited. The amounts below were fixed with the price and
        exchange rate that applied at the time of purchase.
    </p>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-body-secondary">Who and where</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Company</dt>
                        <dd class="col-sm-7">{{ $transaction->company->name }}</dd>
                        <dt class="col-sm-5">Station</dt>
                        <dd class="col-sm-7">{{ $transaction->station->name }}</dd>
                        <dt class="col-sm-5">Card</dt>
                        <dd class="col-sm-7 font-monospace">{{ Redact::cardNumber($transaction->fuelCard->card_no) }}</dd>
                        <dt class="col-sm-5">Vehicle</dt>
                        <dd class="col-sm-7">{{ $transaction->vehicle?->plate_no ?? 'None' }}</dd>
                        <dt class="col-sm-5">Driver</dt>
                        <dd class="col-sm-7">{{ $transaction->driver?->name ?? 'None' }}</dd>
                        <dt class="col-sm-5">Odometer</dt>
                        <dd class="col-sm-7">{{ $transaction->odometer_km !== null ? number_format($transaction->odometer_km).' km' : 'Not recorded' }}</dd>
                        <dt class="col-sm-5">Purchased at (Beirut)</dt>
                        <dd class="col-sm-7">{{ Display::businessTime($transaction->transacted_at) }}</dd>
                        <dt class="col-sm-5">Received at (Beirut)</dt>
                        <dd class="col-sm-7">{{ $transaction->created_at ? Display::businessTime($transaction->created_at) : 'Unknown' }}</dd>
                        <dt class="col-sm-5">Quota month</dt>
                        <dd class="col-sm-7 mb-0">{{ $transaction->quota_month->format('F Y') }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h6 text-body-secondary">Fuel, price and exchange rate</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Product</dt>
                        <dd class="col-sm-7">{{ $transaction->product->name }}</dd>
                        <dt class="col-sm-5">Liters</dt>
                        <dd class="col-sm-7">{{ Display::decimal($transaction->liters) }}</dd>
                        <dt class="col-sm-5">Unit price</dt>
                        <dd class="col-sm-7">{{ Display::decimal($transaction->unit_price_lbp, 4) }} LBP per liter</dd>
                        <dt class="col-sm-5">Amount (LBP)</dt>
                        <dd class="col-sm-7">{{ Display::decimal($transaction->amount_lbp) }}</dd>
                        <dt class="col-sm-5">Exchange rate</dt>
                        <dd class="col-sm-7">{{ Display::decimal($transaction->rate_lbp_per_usd, 8) }} LBP per USD</dd>
                        <dt class="col-sm-5">Rate source</dt>
                        <dd class="col-sm-7">
                            @include('partials.rate-source-badge', ['source' => $transaction->rate_source])
                            <span class="d-block small">effective {{ Display::businessTime($transaction->rate_effective_at) }} (Beirut time)</span>
                        </dd>
                        <dt class="col-sm-5">Amount (USD)</dt>
                        <dd class="col-sm-7 mb-0 fw-semibold">{{ Display::decimal($transaction->amount_usd) }}</dd>
                    </dl>
                    @if ($transaction->rate_source === App\Enums\RateSource::Provider)
                        @include('partials.rate-attribution')
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
