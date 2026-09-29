@extends('layouts.app')

@use('App\Enums\RateMode')
@use('App\Enums\RateSource')
@use('App\Support\Display')

@section('title', 'Exchange rates')

@section('content')
    <h1 class="h3 mb-3">USD/LBP exchange rates</h1>

    <p>
        @if ($mode === RateMode::Live)
            <span class="badge text-bg-primary">Live mode</span>
            Rates come from the external provider through the daily sync. Fixture rates are ignored.
        @else
            <span class="badge text-bg-secondary">Fixture mode</span>
            Synthetic demo rates with a fictional value; no provider is called.
        @endif
        Manual overrides apply in both modes and take precedence while they are valid.
    </p>

    @if ($state?->last_error_code)
        <div class="alert alert-warning" role="status">
            <strong>Sync degraded.</strong> {{ $state->errorDescription() }}
            Stored rates are unchanged and keep working until they expire.
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Rate in effect now</h2>
                    @if ($current)
                        <p class="display-6 font-monospace mb-2">{{ Display::decimal($current->rate, 8) }} <span class="fs-6">LBP per USD</span></p>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Source</dt>
                            <dd class="col-sm-8">{{ $current->source->label() }}</dd>
                            <dt class="col-sm-4">Effective</dt>
                            <dd class="col-sm-8">{{ Display::businessTime($current->effective_at) }} (Beirut time)</dd>
                            <dt class="col-sm-4">Expires</dt>
                            <dd class="col-sm-8">{{ Display::businessTime($current->expires_at) }} ({{ $current->expires_at->diffForHumans($now) }})</dd>
                            @if ($current->source === RateSource::Manual)
                                <dt class="col-sm-4">Reason</dt>
                                <dd class="col-sm-8">{{ $current->reason }}</dd>
                            @endif
                        </dl>
                    @else
                        <div class="alert alert-danger mb-0" role="alert">
                            <strong>No valid rate.</strong> New POS purchases are refused (<code>rate_unavailable</code>)
                            until a sync succeeds or an admin enters an override. Past purchases keep their stored amounts.
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Last sync <span class="small text-body-secondary">({{ $mode->value }} mode)</span></h2>
                    @if ($state?->last_attempt_at)
                        <dl class="row">
                            <dt class="col-sm-5">Last attempt</dt>
                            <dd class="col-sm-7">{{ Display::businessTime($state->last_attempt_at) }}</dd>
                            <dt class="col-sm-5">Last success</dt>
                            <dd class="col-sm-7">{{ $state->last_success_at ? Display::businessTime($state->last_success_at) : 'Never' }}</dd>
                            <dt class="col-sm-5">Result</dt>
                            <dd class="col-sm-7">{{ $state->last_error_code ? 'Failed or degraded: '.$state->last_error_code : 'OK' }}</dd>
                            <dt class="col-sm-5">Next provider call</dt>
                            <dd class="col-sm-7">{{ $state->next_attempt_at ? 'Not before '.Display::businessTime($state->next_attempt_at) : 'At the next run' }}</dd>
                        </dl>
                    @else
                        <p>No sync has run in this mode yet.</p>
                    @endif
                    <p class="small text-body-secondary mb-0">
                        The scheduler runs <code>rates:sync</code> daily at {{ config('fleetfuel.exchange_rates.sync_daily_at') }} UTC.
                        To sync now: <code>docker compose exec app php artisan rates:sync</code>.
                        Pages never call the provider themselves.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <form method="POST" action="{{ route('integrations.exchange-rates.overrides.store') }}" class="card card-body shadow-sm h-100">
                @csrf
                <h2 class="h5">Enter a manual override</h2>
                <p class="small text-body-secondary">
                    An override cannot start in the past, lasts at most {{ config('fleetfuel.exchange_rates.max_age_hours') }} hours
                    and cannot be edited afterwards; a newer override takes precedence. It never changes past purchases.
                </p>
                <x-form.input name="rate" label="Rate (LBP per USD)" required inputmode="decimal"
                              help="Up to 8 decimal places, for example 89500 or 89500.25." />
                <x-form.input name="reason" label="Reason" required maxlength="255" />
                <x-form.input name="valid_for_hours" type="number" label="Valid for (hours)" required min="1"
                              :max="config('fleetfuel.exchange_rates.max_age_hours')" value="24" />
                <x-form.input name="starts_at" type="datetime-local" label="Starts at (Beirut time)"
                              help="Leave empty to start now." />
                <div>
                    <button type="submit" class="btn btn-primary">Save override</button>
                </div>
            </form>
        </div>

        <div class="col-lg-6">
            <h2 class="h5">Recent overrides</h2>
            @if ($overrides->isEmpty())
                <p class="text-body-secondary">No manual overrides yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle bg-body">
                        <thead>
                            <tr>
                                <th scope="col" class="text-end">LBP per USD</th>
                                <th scope="col">Valid (Beirut time)</th>
                                <th scope="col">Status</th>
                                <th scope="col">Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($overrides as $override)
                                <tr>
                                    <td class="text-end font-monospace">{{ Display::decimal($override->rate, 8) }}</td>
                                    <td class="small">{{ Display::businessTime($override->effective_at) }} to {{ Display::businessTime($override->expires_at) }}</td>
                                    <td>@include('integrations.rate-status', ['rate' => $override])</td>
                                    <td class="small">{{ $override->reason }} <span class="text-body-secondary">({{ $override->creator?->name }})</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    <h2 class="h5">Recent {{ strtolower($mode->source()->label()) }} observations</h2>
    @if ($observations->isEmpty())
        <p class="text-body-secondary">None stored yet.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Observed (Beirut time)</th>
                        <th scope="col" class="text-end">LBP per USD</th>
                        <th scope="col">Expires</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($observations as $observation)
                        <tr>
                            <td>{{ Display::businessTime($observation->effective_at) }}</td>
                            <td class="text-end font-monospace">{{ Display::decimal($observation->rate, 8) }}</td>
                            <td>{{ Display::businessTime($observation->expires_at) }}</td>
                            <td>@include('integrations.rate-status', ['rate' => $observation])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($mode === RateMode::Live || $current?->source === RateSource::Provider)
        @include('partials.rate-attribution')
    @endif
@endsection
