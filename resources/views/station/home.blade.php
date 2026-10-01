@extends('layouts.app')

@section('title', 'Station')

@section('content')
    <div class="mb-3">
        <h1 class="h3 mb-0">{{ $user->station->name }}</h1>
        <span class="text-body-secondary">{{ $user->station->district }}, {{ $user->station->governorate }}</span>
    </div>

    @unless ($user->station->is_active)
        <div class="alert alert-warning" role="alert">
            This station is inactive. POS purchases from it will be declined.
        </div>
    @endunless

    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
        <h2 class="h5">Latest purchases at this station</h2>
        <a class="small" href="{{ route('transactions.index') }}">All purchases, with filters and totals</a>
    </div>
    @include('transactions._table', [
        'transactions' => $purchases,
        'showCompany' => true,
        'showStation' => false,
    ])

    <h2 class="h5 mt-4">POS API access</h2>
    <p>
        The point-of-sale terminal signs in with its own API token, not with this browser session.
        Request a token with your account's credentials. It is shown once, expires after 24 hours
        and can be revoked at any time:
    </p>
<pre class="bg-body border rounded p-3 small"><code>curl -X POST {{ url('/api/v1/auth/token') }} \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email": "{{ $user->email }}", "password": "YOUR-PASSWORD", "device_name": "pos-terminal-1"}'</code></pre>
    <p>
        Send each purchase with the token. The station is always yours; a unique <code>external_ref</code>
        per purchase makes a retry safe (an identical retry returns the original purchase instead of charging twice).
        Liters are a decimal string and the time must carry its UTC offset:
    </p>
<pre class="bg-body border rounded p-3 small"><code>curl -X POST {{ url('/api/v1/transactions') }} \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer YOUR-TOKEN' \
  -d '{"external_ref": "POS-0001", "card_no": "FF-ATLAS-001", "product_code": "DIESEL",
       "liters": "20.00", "transacted_at": "{{ now()->setTimezone(config('fleetfuel.business_timezone'))->format('Y-m-d\TH:i:sP') }}"}'</code></pre>
    <p>
        Check a card before fuelling with <code>GET /api/v1/cards/{card_no}/balance</code>. Revoke the token with
        <code>DELETE /api/v1/auth/token</code>, sending it as <code>Authorization: Bearer …</code>.
    </p>

    <h2 class="h5 mt-4">POS simulator</h2>
    <p class="mb-0">
        The project's standalone simulator (<code>tools/pos-simulator</code>) plays a terminal through this API and
        checks each answer: a purchase, an identical and a changed retry, a blocked card and an over-quota request.
        From the project folder, with your credentials only in the environment:
        <code>POS_EMAIL={{ $user->email }} POS_PASSWORD=… make simulate</code>.
        Its README explains the options and how to prepare fresh cards for another run.
    </p>
@endsection
