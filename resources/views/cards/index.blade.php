@extends('layouts.app')

@use('App\Support\CardBalance')
@use('App\Support\Display')
@use('App\Support\Redact')
@use('Carbon\CarbonImmutable')

@section('title', 'Fuel cards')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Fuel cards</h1>
        @can('create', App\Models\FuelCard::class)
            <a class="btn btn-primary" href="{{ route('cards.create') }}">Issue card</a>
        @endcan
    </div>

    @include('partials.list-filters', [
        'searchLabel' => 'Card number (or part of it)',
        'statusOptions' => ['active' => 'Active', 'blocked' => 'Blocked', 'archived' => 'Archived'],
        'companies' => $companies,
    ])

    <p class="small text-body-secondary">
        Usage for {{ CarbonImmutable::parse($month)->format('F Y') }} (Beirut time) against the card's current monthly limits.
    </p>

    @if ($cards->isEmpty())
        <p class="text-body-secondary">No cards match these filters.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Card</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">Vehicle / driver</th>
                        <th scope="col">Product</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Liters used / limit</th>
                        <th scope="col" class="text-end">USD used / limit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($cards as $card)
                        @php($balance = CardBalance::for($card, $card->monthlyUsages->first(), $month))
                        <tr>
                            <td class="font-monospace"><a href="{{ route('cards.show', $card) }}">{{ Redact::cardNumber($card->card_no) }}</a></td>
                            @if ($companies !== null)
                                <td>{{ $card->company->name }}</td>
                            @endif
                            <td>
                                {{ $card->vehicle?->plate_no ?? 'No vehicle' }}
                                <span class="d-block small text-body-secondary">{{ $card->driver?->name ?? 'No driver' }}</span>
                            </td>
                            <td>{{ $card->allowedProduct?->name ?? 'Any' }}</td>
                            <td>
                                @include('partials.card-status-badge', ['status' => $card->status])
                                @if ($balance->isOverQuota())
                                    <span class="badge text-bg-warning">Over quota</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">{{ Display::decimal($balance->usedL) }} / {{ $balance->limitL === null ? 'no limit' : Display::decimal($balance->limitL) }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($balance->usedUsd) }} / {{ $balance->limitUsd === null ? 'no limit' : Display::decimal($balance->limitUsd) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $cards->links() }}
    @endif
@endsection
