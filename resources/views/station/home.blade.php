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

    <h2 class="h5">Latest purchases at this station</h2>
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
    <p class="mb-0">
        Revoke it with <code>DELETE /api/v1/auth/token</code>, sending the token as
        <code>Authorization: Bearer …</code>. Purchase submission (<code>POST /api/v1/transactions</code>)
        is not available yet.
    </p>
@endsection
