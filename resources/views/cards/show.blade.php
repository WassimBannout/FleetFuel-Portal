@extends('layouts.app')

@use('App\Enums\CardStatus')
@use('App\Support\Display')
@use('Carbon\CarbonImmutable')

@section('title', 'Card '.$card->card_no)

@section('content')
    @php
        $archived = $card->status === CardStatus::Archived;
        $canUpdate = auth()->user()->can('update', $card);
        $limitText = fn (?string $value) => $value === null ? 'No limit' : Display::decimal($value);
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
        <h1 class="h3 mb-0">Card <span class="font-monospace">{{ $card->card_no }}</span> @include('partials.card-status-badge', ['status' => $card->status])</h1>
        <span class="text-body-secondary">{{ $card->company->name }}</span>
    </div>

    @if ($balance->isOverQuota())
        <div class="alert alert-warning" role="status">
            Over quota this month: a limit was lowered below what the card had already used.
            Any further purchase this month would exceed the limit.
        </div>
    @endif
    @if ($archived)
        <div class="alert alert-secondary" role="status">This card is archived. It can no longer be used or changed; its history is kept.</div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Assignment</h2>
                    <dl class="row mb-2">
                        <dt class="col-sm-5">Vehicle</dt>
                        <dd class="col-sm-7">{{ $card->vehicle ? $card->vehicle->plate_no.' ('.$card->vehicle->fuel_type->value.')' : 'None' }}</dd>
                        <dt class="col-sm-5">Driver</dt>
                        <dd class="col-sm-7">{{ $card->driver?->name ?? 'None' }}</dd>
                        <dt class="col-sm-5">Product restriction</dt>
                        <dd class="col-sm-7">{{ $card->allowedProduct?->name ?? 'Any product the vehicle can use' }}</dd>
                    </dl>
                    @if ($used)
                        <p class="small text-body-secondary mb-0">Locked: the card has been used.</p>
                    @elseif ($canUpdate && ! $archived)
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('cards.edit', $card) }}">Change assignment</a>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">{{ CarbonImmutable::parse($balance->month)->format('F Y') }} <span class="small text-body-secondary">(Beirut time)</span></h2>
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr><th scope="col"></th><th scope="col" class="text-end">Liters</th><th scope="col" class="text-end">USD</th></tr>
                        </thead>
                        <tbody>
                            <tr><th scope="row">Monthly limit</th><td class="text-end">{{ $limitText($balance->limitL) }}</td><td class="text-end">{{ $limitText($balance->limitUsd) }}</td></tr>
                            <tr><th scope="row">Used</th><td class="text-end">{{ Display::decimal($balance->usedL) }}</td><td class="text-end">{{ Display::decimal($balance->usedUsd) }}</td></tr>
                            <tr><th scope="row">Remaining</th><td class="text-end">{{ $limitText($balance->remainingL()) }}</td><td class="text-end">{{ $limitText($balance->remainingUsd()) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if ($canUpdate && ! $archived)
        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <form method="POST" action="{{ route('cards.limits', $card) }}" class="card card-body shadow-sm h-100">
                    @csrf
                    @method('PATCH')
                    <h2 class="h5">Monthly limits</h2>

                    @error('confirm_below_usage')
                        <div class="alert alert-warning" role="alert">
                            {{ $message }}
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="confirm_below_usage" value="1" id="confirm_below_usage">
                                <label class="form-check-label" for="confirm_below_usage">I understand the card will be over quota.</label>
                            </div>
                        </div>
                    @enderror

                    <div class="row">
                        <div class="col-md-6">
                            <x-form.input name="monthly_limit_l" label="Liters" :value="$card->monthly_limit_l" inputmode="decimal" />
                        </div>
                        <div class="col-md-6">
                            <x-form.input name="monthly_limit_usd" label="USD" :value="$card->monthly_limit_usd" inputmode="decimal" />
                        </div>
                    </div>
                    <p class="form-text mt-0">Leave empty for no limit; 0 allows no further purchases. Changes are audited.</p>
                    <div><button type="submit" class="btn btn-primary">Save limits</button></div>
                </form>
            </div>

            <div class="col-lg-6">
                <div class="card card-body shadow-sm h-100">
                    <h2 class="h5">Status</h2>
                    <p class="small text-body-secondary">Blocking takes effect for every station at once. Archiving is final.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('cards.status', $card) }}">
                            @csrf
                            @method('PATCH')
                            @if ($card->status === CardStatus::Active)
                                <input type="hidden" name="status" value="blocked">
                                <button type="submit" class="btn btn-warning">Block card</button>
                            @else
                                <input type="hidden" name="status" value="active">
                                <button type="submit" class="btn btn-outline-success">Unblock card</button>
                            @endif
                        </form>
                        <form method="POST" action="{{ route('cards.status', $card) }}"
                              data-confirm="Archive card {{ $card->card_no }}? This cannot be undone.">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="archived">
                            <button type="submit" class="btn btn-outline-danger">Archive card</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <h2 class="h5">Latest purchases</h2>
    @include('transactions._table', ['transactions' => $recent, 'showCompany' => false, 'showStation' => true])

    @if ($audit !== null)
        <h2 class="h5 mt-4">Audit history</h2>
        @if ($audit->isEmpty())
            <p class="text-body-secondary">No recorded changes.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle bg-body">
                    <thead>
                        <tr>
                            <th scope="col">Time (Beirut)</th>
                            <th scope="col">Change</th>
                            <th scope="col">By</th>
                            <th scope="col">Before</th>
                            <th scope="col">After</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($audit as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->created_at ? Display::businessTime($entry->created_at) : '' }}</td>
                                <td class="font-monospace small">{{ $entry->action }}</td>
                                <td>{{ $entry->user?->name ?? 'Command line / system' }}</td>
                                <td class="font-monospace small">{{ $entry->old_values ? json_encode($entry->old_values) : '—' }}</td>
                                <td class="font-monospace small">{{ $entry->new_values ? json_encode($entry->new_values) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
