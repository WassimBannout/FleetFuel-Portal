<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="d-flex flex-column min-vh-100 bg-body-tertiary">
    <nav class="navbar navbar-expand-md bg-dark" data-bs-theme="dark">
        <div class="container">
            @auth
                <a class="navbar-brand" href="{{ route(auth()->user()->role->homeRoute()) }}">{{ config('app.name') }}</a>

                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#main-nav"
                        aria-controls="main-nav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="main-nav">
                    @php
                        $navUser = auth()->user();
                        // [route, label, active-route pattern]; links follow the same policies as the pages.
                        $navLinks = array_filter([
                            $navUser->isStationOperator() ? ['station.home', 'Station', 'station.home'] : ['dashboard', 'Dashboard', 'dashboard'],
                            $navUser->can('viewAny', App\Models\Company::class) ? ['companies.index', 'Companies', 'companies.*'] : null,
                            $navUser->can('viewAny', App\Models\Vehicle::class) ? ['vehicles.index', 'Vehicles', 'vehicles.*'] : null,
                            $navUser->can('viewAny', App\Models\Driver::class) ? ['drivers.index', 'Drivers', 'drivers.*'] : null,
                            $navUser->can('viewAny', App\Models\FuelCard::class) ? ['cards.index', 'Cards', 'cards.*'] : null,
                            ['stations.index', 'Stations', 'stations.*'],
                            ['products.index', 'Products', 'products.*'],
                            $navUser->can('viewAny', App\Models\ExchangeRate::class) ? ['integrations.exchange-rates', 'Exchange rates', 'integrations.*'] : null,
                        ]);
                    @endphp
                    <ul class="navbar-nav me-auto">
                        @foreach ($navLinks as [$navRoute, $navLabel, $navPattern])
                            <li class="nav-item">
                                <a @class(['nav-link', 'active' => request()->routeIs($navPattern)])
                                   @if (request()->routeIs($navPattern)) aria-current="page" @endif
                                   href="{{ route($navRoute) }}">{{ $navLabel }}</a>
                            </li>
                        @endforeach
                    </ul>

                    <span class="navbar-text small me-md-3">
                        {{ auth()->user()->name }} · {{ auth()->user()->role->label() }}
                    </span>

                    {{-- Signing out changes state, so it is a POST with a CSRF token, never a link. --}}
                    <form method="POST" action="{{ route('logout') }}" class="my-2 my-md-0">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm">Sign out</button>
                    </form>
                </div>
            @else
                <a class="navbar-brand" href="{{ route('home') }}">{{ config('app.name') }}</a>

                @unless (request()->routeIs('login'))
                    <a class="btn btn-outline-light btn-sm" href="{{ route('login') }}">Sign in</a>
                @endunless
            @endauth
        </div>
    </nav>

    <main class="container flex-grow-1 py-4">
        @if (session('status'))
            <div class="alert alert-info" role="status">{{ session('status') }}</div>
        @endif
        @error('rule')
            <div class="alert alert-danger" role="alert">{{ $message }}</div>
        @enderror

        @yield('content')
    </main>

    <footer class="container py-3 small text-body-secondary">
        Portfolio demonstration using fictional companies, people and prices.
        Not affiliated with any fuel distributor.
    </footer>
</body>
</html>
