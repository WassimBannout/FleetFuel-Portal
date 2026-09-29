@extends('layouts.app')

@use('App\Support\Display')
@use('App\Support\FuelAmounts')

@section('title', $product->code.' prices')

@section('content')
    @php($admin = auth()->user()->isAdmin())

    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
        <h1 class="h3 mb-0">Prices of <span class="font-monospace">{{ $product->code }}</span></h1>
        <a href="{{ route('products.index') }}">All products</a>
    </div>

    <p class="text-body-secondary">
        {{ $product->name }}. Prices are in <strong>LBP per liter</strong>. Published prices never change:
        a purchase keeps the price that applied when it happened, and a correction is a new, later price.
    </p>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Current price</h2>
                    @if ($current)
                        <p class="display-6 font-monospace mb-1">{{ Display::decimal($current->price_lbp, 4) }} <span class="fs-6">LBP per liter</span></p>
                        <p class="mb-2">
                            Since {{ Display::businessTime($current->effective_from) }} (Beirut time)
                            @if ($rate)
                                · about <span class="font-monospace">{{ FuelAmounts::indicativeUnitPriceUsd($current->price_lbp, $rate->rate) }}</span> USD per liter
                            @endif
                        </p>
                        @include('partials.indicative-rate-note', ['rate' => $rate])
                    @else
                        <p class="mb-0">No price is in effect yet, so this product cannot be sold.</p>
                    @endif
                </div>
            </div>
        </div>

        @if ($admin)
            <div class="col-lg-6">
                <form method="POST" action="{{ route('products.prices.store', $product) }}" class="card card-body shadow-sm h-100">
                    @csrf
                    <h2 class="h5">Publish a price</h2>
                    <x-form.input name="price_lbp" label="Price (LBP per liter)" required inputmode="decimal"
                                  help="Up to 4 decimal places, for example 80000 or 80000.5." />
                    <x-form.input name="effective_from" type="datetime-local" label="Starts at (Beirut time)"
                                  help="Leave empty to start now. A past time is refused." />
                    <div>
                        <button type="submit" class="btn btn-primary">Publish price</button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    <h2 class="h5">Timeline</h2>
    @if ($prices->isEmpty())
        <p class="text-body-secondary">No prices yet.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Starts (Beirut time)</th>
                        <th scope="col" class="text-end">Price (LBP per liter)</th>
                        <th scope="col">Status</th>
                        @if ($admin)
                            <th scope="col">Published by</th>
                            <th scope="col">Published at</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($prices as $price)
                        <tr>
                            <td>{{ Display::businessTime($price->effective_from) }}</td>
                            <td class="text-end font-monospace">{{ Display::decimal($price->price_lbp, 4) }}</td>
                            <td>
                                @if ($price->effective_from->isAfter($now))
                                    <span class="badge text-bg-info">Scheduled</span>
                                @elseif ($current && $price->is($current))
                                    <span class="badge text-bg-success">Current</span>
                                @else
                                    <span class="badge text-bg-secondary">Earlier</span>
                                @endif
                            </td>
                            @if ($admin)
                                <td>{{ $price->creator->name }}</td>
                                <td>{{ $price->created_at ? Display::businessTime($price->created_at) : '' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $prices->links() }}
    @endif
@endsection
