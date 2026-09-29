@extends('layouts.app')

@use('App\Support\Display')
@use('App\Support\FuelAmounts')

@section('title', 'Products')

@section('content')
    @php($admin = auth()->user()->isAdmin())

    <h1 class="h3 mb-3">{{ $admin ? 'Products' : 'Active products' }}</h1>

    @if ($products->isEmpty())
        <p class="text-body-secondary">No products yet.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Name</th>
                        <th scope="col">Fuel type</th>
                        <th scope="col" class="text-end">Current price<br><span class="fw-normal small">LBP per liter</span></th>
                        <th scope="col" class="text-end">Indicative<br><span class="fw-normal small">USD per liter</span></th>
                        @if ($admin)
                            <th scope="col">Status</th>
                        @endif
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $product)
                        @php($price = $currentPrices[$product->id])
                        <tr>
                            <td class="font-monospace">{{ $product->code }}</td>
                            <td>{{ $product->name }}</td>
                            <td>{{ ucfirst($product->fuel_type->value) }}</td>
                            <td class="text-end font-monospace">{{ $price ? Display::decimal($price->price_lbp, 4) : 'No price yet' }}</td>
                            <td class="text-end font-monospace">{{ $price && $rate ? FuelAmounts::indicativeUnitPriceUsd($price->price_lbp, $rate->rate) : '—' }}</td>
                            @if ($admin)
                                <td>@include('partials.active-badge', ['active' => $product->is_active])</td>
                            @endif
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('products.prices.index', $product) }}">Prices<span class="visually-hidden"> of {{ $product->code }}</span></a>
                                @if ($admin)
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('products.edit', $product) }}">Edit<span class="visually-hidden"> {{ $product->code }}</span></a>
                                    @include('partials.active-toggle', ['action' => route('products.active', $product), 'active' => $product->is_active, 'name' => $product->code])
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('partials.indicative-rate-note', ['rate' => $rate])
    @endif
@endsection
