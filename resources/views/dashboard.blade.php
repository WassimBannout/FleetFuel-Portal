@extends('layouts.app')

@use('App\Support\Display')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
        <h1 class="h3 mb-0">Dashboard</h1>
        <span class="text-body-secondary">
            {{ $user->isAdmin() ? 'All companies' : $user->company?->name }}
            · {{ $monthStart->format('F Y') }} (Beirut time)
        </span>
    </div>

    @php
        $tiles = array_filter([
            'Purchases this month' => (string) ($monthTotals?->purchases ?? 0),
            'Liters this month' => Display::decimal($monthTotals?->liters ?? '0'),
            'Spend this month (USD)' => Display::decimal($monthTotals?->amount_usd ?? '0'),
            'Spend this month (LBP)' => Display::decimal($monthTotals?->amount_lbp ?? '0'),
            'Companies' => $companies === null ? null : (string) $companies,
            'Active vehicles' => (string) $vehicles,
            'Active drivers' => (string) $drivers,
            'Active cards' => (string) $activeCards,
            'Blocked cards' => (string) $blockedCards,
            'Open deliveries' => (string) $openDeliveries,
        ], fn ($value) => $value !== null);
    @endphp

    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
        @foreach ($tiles as $label => $value)
            <div class="col">
                <div class="card h-100 shadow-sm">
                    <div class="card-body">
                        <div class="small text-body-secondary">{{ $label }}</div>
                        <div class="fs-5 fw-semibold">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <h2 class="h5">Latest purchases</h2>
    @include('transactions._table', [
        'transactions' => $recent,
        'showCompany' => $user->isAdmin(),
        'showStation' => true,
    ])
@endsection
