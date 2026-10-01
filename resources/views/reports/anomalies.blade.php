@extends('layouts.app')

@use('App\Support\Display')
@use('Brick\Math\BigDecimal')

@section('title', 'Anomalies')

@section('content')
    @include('reports._header', ['title' => 'Anomalies'])

    <h3 class="h6">More than the tank holds</h3>
    <p class="small">Purchases of more liters than the vehicle's tank capacity recorded at purchase time. Purchases without a vehicle are not compared.</p>
    @if ($overfills === [])
        <p class="text-body-secondary">None in this period.</p>
    @else
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Purchase</th>
                        <th scope="col">Time (Beirut)</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">Vehicle</th>
                        <th scope="col">Station</th>
                        <th scope="col" class="text-end">Liters</th>
                        <th scope="col" class="text-end">Tank</th>
                        <th scope="col" class="text-end">Excess</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($overfills as $row)
                        <tr>
                            <td><a href="{{ route('transactions.show', $row['id']) }}">#{{ $row['id'] }}</a></td>
                            <td class="text-nowrap">{{ Display::businessTime($row['transacted_at']) }}</td>
                            @if ($companies !== null)
                                <td>{{ $row['company'] }}</td>
                            @endif
                            <td>{{ $row['vehicle_plate'] }}</td>
                            <td>{{ $row['station'] }}</td>
                            <td class="text-end">{{ Display::decimal($row['liters']) }}</td>
                            <td class="text-end">{{ Display::decimal($row['tank_capacity_l']) }}</td>
                            <td class="text-end">{{ Display::decimal((string) BigDecimal::of($row['liters'])->minus($row['tank_capacity_l'])) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h3 class="h6">Rapid fills</h3>
    <p class="small">Fills less than 30 minutes after the same vehicle's previous fill, including a previous fill just before this period. Two fills in the same second count, ordered by purchase number. Card-only purchases have no vehicle and are never flagged.</p>
    @if ($rapidFills === [])
        <p class="text-body-secondary">None in this period.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Purchase</th>
                        <th scope="col">Time (Beirut)</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">Vehicle</th>
                        <th scope="col">Station</th>
                        <th scope="col" class="text-end">Liters</th>
                        <th scope="col">Previous fill</th>
                        <th scope="col" class="text-end">Minutes apart</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rapidFills as $row)
                        <tr>
                            <td><a href="{{ route('transactions.show', $row['id']) }}">#{{ $row['id'] }}</a></td>
                            <td class="text-nowrap">{{ Display::businessTime($row['transacted_at']) }}</td>
                            @if ($companies !== null)
                                <td>{{ $row['company'] }}</td>
                            @endif
                            <td>{{ $row['vehicle_plate'] }}</td>
                            <td>{{ $row['station'] }}</td>
                            <td class="text-end">{{ Display::decimal($row['liters']) }}</td>
                            <td class="text-nowrap"><a href="{{ route('transactions.show', $row['previous_id']) }}">#{{ $row['previous_id'] }}</a> at {{ Display::businessTime($row['previous_at']) }}</td>
                            <td class="text-end">{{ intdiv($row['seconds_since_previous'], 60) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
