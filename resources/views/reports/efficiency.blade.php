@extends('layouts.app')

@use('App\Support\Display')

@section('title', 'Efficiency estimate')

@section('content')
    @include('reports._header', ['title' => 'Fuel efficiency (estimate)'])

    <p class="small">
        <strong>An estimate.</strong> Kilometers since the vehicle's previous fill, divided by the liters of this fill. It assumes every fill fills the tank (full to full).
        Without a previous fill, an odometer reading on both fills, or a higher reading than last time, no figure is shown. Readings are the ones recorded at the pump, never the vehicle's current odometer.
    </p>

    @if ($rows === [])
        <p class="text-body-secondary">No vehicle fills in this period.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Vehicle</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">Purchase</th>
                        <th scope="col">Time (Beirut)</th>
                        <th scope="col" class="text-end">Liters</th>
                        <th scope="col" class="text-end">Odometer (km)</th>
                        <th scope="col" class="text-end">Distance (km)</th>
                        <th scope="col" class="text-end">km per liter</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['vehicle_plate'] }}</td>
                            @if ($companies !== null)
                                <td>{{ $row['company'] }}</td>
                            @endif
                            <td><a href="{{ route('transactions.show', $row['id']) }}">#{{ $row['id'] }}</a></td>
                            <td class="text-nowrap">{{ Display::businessTime($row['transacted_at']) }}</td>
                            <td class="text-end">{{ Display::decimal($row['liters']) }}</td>
                            <td class="text-end">{{ $row['odometer_km'] === null ? '—' : number_format($row['odometer_km']) }}</td>
                            <td class="text-end">{{ $row['distance_km'] === null ? '—' : number_format($row['distance_km']) }}</td>
                            <td class="text-end">
                                @if ($row['km_per_liter'] !== null)
                                    {{ $row['km_per_liter'] }}
                                @else
                                    <span class="small text-body-secondary">{{ $row['note'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
