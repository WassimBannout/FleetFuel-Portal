<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-body-tertiary">
    <a class="visually-hidden-focusable skip-link" href="#main">Skip to main content</a>

    @auth
        @php
            $navUser = auth()->user();
            // Grouped [route, label, active-route pattern]; links follow the same policies as the pages.
            $navGroups = array_filter(array_map('array_filter', [
                'Overview' => [
                    $navUser->isStationOperator() ? ['station.home', 'Station', 'station.home'] : ['dashboard', 'Dashboard', 'dashboard'],
                    $navUser->can('viewAny', App\Models\FuelTransaction::class) ? ['transactions.index', 'Transactions', 'transactions.*'] : null,
                    $navUser->can('viewReports') ? ['reports.consumption', 'Reports', 'reports.*'] : null,
                    $navUser->can('viewAny', App\Models\DeliveryOrder::class) ? ['deliveries.index', 'Deliveries', 'deliveries.*'] : null,
                ],
                'Fleet' => [
                    $navUser->can('viewAny', App\Models\Company::class) ? ['companies.index', 'Companies', 'companies.*'] : null,
                    $navUser->can('viewAny', App\Models\Vehicle::class) ? ['vehicles.index', 'Vehicles', 'vehicles.*'] : null,
                    $navUser->can('viewAny', App\Models\Driver::class) ? ['drivers.index', 'Drivers', 'drivers.*'] : null,
                    $navUser->can('viewAny', App\Models\FuelCard::class) ? ['cards.index', 'Cards', 'cards.*'] : null,
                ],
                'Reference data' => [
                    ['stations.index', 'Stations', 'stations.*'],
                    ['products.index', 'Products and prices', 'products.*'],
                ],
                'Administration' => [
                    $navUser->can('viewAny', App\Models\ExchangeRate::class) ? ['integrations.exchange-rates', 'Exchange rates', 'integrations.*'] : null,
                    $navUser->can('viewAny', App\Models\AuditLog::class) ? ['audit.index', 'Audit log', 'audit.*'] : null,
                ],
            ]));
            $homeUrl = route($navUser->role->homeRoute());
        @endphp

        <div class="app-shell d-lg-flex">
            {{-- Below 992px: a top bar with a menu button that opens the sidebar as an off-canvas panel. --}}
            <header class="app-topbar navbar d-lg-none" data-bs-theme="dark">
                <div class="container-fluid">
                    <a class="navbar-brand" href="{{ $homeUrl }}">{{ config('app.name') }}</a>
                    <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#app-sidebar"
                            aria-controls="app-sidebar">
                        Menu
                    </button>
                </div>
            </header>

            {{-- A plain wrapper: the landmark is the <nav> inside, not a "complementary" region. --}}
            <div class="app-sidebar">
                <div class="offcanvas-lg offcanvas-start" id="app-sidebar" tabindex="-1" aria-labelledby="app-sidebar-title" data-bs-theme="dark">
                    <div class="offcanvas-header">
                        <span class="h5 mb-0 text-white" id="app-sidebar-title">{{ config('app.name') }}</span>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#app-sidebar" aria-label="Close menu"></button>
                    </div>
                    <div class="offcanvas-body flex-column w-100 p-3">
                        <a class="brand fs-5 d-none d-lg-block px-2 mb-2" href="{{ $homeUrl }}">{{ config('app.name') }}</a>

                        <nav aria-label="Main">
                            @foreach ($navGroups as $groupLabel => $groupLinks)
                                <h2 class="nav-section">{{ $groupLabel }}</h2>
                                <ul class="nav flex-column">
                                    @foreach ($groupLinks as [$navRoute, $navLabel, $navPattern])
                                        <li class="nav-item">
                                            <a @class(['nav-link', 'active' => request()->routeIs($navPattern)])
                                               @if (request()->routeIs($navPattern)) aria-current="page" @endif
                                               href="{{ route($navRoute) }}">{{ $navLabel }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endforeach
                        </nav>

                        <div class="account mt-auto pt-3 px-2">
                            <div class="small">{{ $navUser->name }}</div>
                            <div class="small mb-2">{{ $navUser->role->label() }}</div>
                            {{-- Signing out changes state, so it is a POST with a CSRF token, never a link. --}}
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-light btn-sm">Sign out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex flex-column flex-grow-1 min-vh-100 min-w-0">
                <main id="main" class="app-main container-fluid flex-grow-1 px-3 px-lg-4 py-4" tabindex="-1">
                    @include('layouts._flash')
                    @yield('content')
                </main>
                @include('layouts._footer')
            </div>
        </div>
    @else
        <nav class="app-topbar navbar" data-bs-theme="dark" aria-label="Main">
            <div class="container">
                <a class="navbar-brand" href="{{ route('home') }}">{{ config('app.name') }}</a>

                @unless (request()->routeIs('login'))
                    <a class="btn btn-outline-light btn-sm" href="{{ route('login') }}">Sign in</a>
                @endunless
            </div>
        </nav>

        <div class="d-flex flex-column min-vh-100">
            <main id="main" class="container flex-grow-1 py-4" tabindex="-1">
                @include('layouts._flash')
                @yield('content')
            </main>
            @include('layouts._footer')
        </div>
    @endauth
</body>
</html>
