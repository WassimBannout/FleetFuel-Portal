@extends('layouts.app')

@use('App\Support\Display')

@section('title', 'Top stations')

@section('content')
    @include('reports._header', ['title' => 'Top stations by liters'])

    <p class="small">At most ten stations, most liters first; equal totals are ordered by station number.</p>

    @if ($rows === [])
        <p class="text-body-secondary">No purchases in this period.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">#</th>
                        <th scope="col">Station</th>
                        <th scope="col">Governorate</th>
                        <th scope="col" class="text-end">Purchases</th>
                        <th scope="col" class="text-end">Liters</th>
                        <th scope="col" class="text-end">LBP</th>
                        <th scope="col" class="text-end">USD</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $rank => $row)
                        <tr>
                            <td>{{ $rank + 1 }}</td>
                            <td>{{ $row['station'] }}</td>
                            <td>{{ $row['governorate'] }}</td>
                            <td class="text-end">{{ $row['purchases'] }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['liters']) }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['amount_lbp']) }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['amount_usd']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
