<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    {{--
        Error pages must render even when the database is down, so this layout
        reads no session user and runs no query. API errors never come here:
        they use the JSON envelope (App\Exceptions\ApiErrorRenderer).
    --}}
    @vite(['resources/css/app.css'])
</head>
<body class="bg-body-tertiary">
    <nav class="app-topbar navbar" data-bs-theme="dark" aria-label="Main">
        <div class="container">
            <a class="navbar-brand" href="{{ url('/') }}">{{ config('app.name') }}</a>
        </div>
    </nav>

    <main id="main" class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-9 col-lg-7">
                <p class="text-body-secondary mb-1">Error @yield('code')</p>
                <h1 class="h3">@yield('title')</h1>
                <p class="lead">@yield('message')</p>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    @yield('actions')
                </div>
            </div>
        </div>
    </main>
</body>
</html>
