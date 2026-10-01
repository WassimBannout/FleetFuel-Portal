@extends('layouts.app')

@section('content')
    <div class="p-4 bg-body rounded-3 shadow-sm">
        <h1 class="h3">{{ config('app.name') }}</h1>
        <p class="lead">
            Corporate fleet-fuel cards, station POS purchases and diesel deliveries,
            reported in USD and LBP.
        </p>
        <p class="text-body-secondary">
            Fleet managers run vehicles, drivers and fuel cards with monthly quotas; stations send
            purchases through an API; distributor staff schedule diesel deliveries and read
            consumption reports. Every company, person and price here is fictional.
            Service readiness: <a href="{{ route('health') }}">/health</a>.
        </p>

        @auth
            <a class="btn btn-primary" href="{{ route(auth()->user()->role->homeRoute()) }}">Open the portal</a>
        @else
            <a class="btn btn-primary" href="{{ route('login') }}">Sign in</a>
        @endauth
    </div>
@endsection
