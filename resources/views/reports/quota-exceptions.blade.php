@extends('layouts.app')

@use('App\Support\Display')
@use('App\Support\Redact')

@section('title', 'Quota exceptions')

@section('content')
    @include('reports._header', ['title' => 'Quota exceptions, '.$month->format('F Y'), 'datesApply' => false])

    <p class="small">
        Blocked cards, and cards that have used all of a monthly limit or more, this Beirut month.
        Usage comes from the monthly counter; limits are the cards' current ones. Archived cards are not listed, and declined purchases never count as usage.
    </p>

    @if ($rows === [])
        <p class="text-body-secondary">No card needs attention this month.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Card</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">Vehicle</th>
                        <th scope="col" class="text-end">Liters used / limit</th>
                        <th scope="col" class="text-end">USD used / limit</th>
                        <th scope="col">Why</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="font-monospace text-nowrap"><a href="{{ route('cards.show', $row['card_id']) }}">{{ Redact::cardNumber($row['card_no']) }}</a></td>
                            @if ($companies !== null)
                                <td>{{ $row['company'] }}</td>
                            @endif
                            <td>{{ $row['vehicle_plate'] ?? 'No vehicle' }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['used_l']) }} / {{ $row['monthly_limit_l'] === null ? 'no limit' : Display::decimal($row['monthly_limit_l']) }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['used_usd']) }} / {{ $row['monthly_limit_usd'] === null ? 'no limit' : Display::decimal($row['monthly_limit_usd']) }}</td>
                            <td>
                                <ul class="list-unstyled small mb-0">
                                    @foreach ($row['reasons'] as $reason)
                                        <li>{{ $reason }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
