@extends('layouts.app')

@section('title', 'Delivery SLA')

@section('content')
    @include('reports._header', ['title' => 'Delivery time by governorate'])

    <p class="small">
        Hours from the request (the order's first timeline entry) to delivery (its "delivered" entry), for orders delivered in this period.
        Pending, in-progress and cancelled orders are not included.
    </p>

    @if ($rows === [])
        <p class="text-body-secondary">No deliveries were completed in this period.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Governorate</th>
                        <th scope="col" class="text-end">Delivered</th>
                        <th scope="col" class="text-end">Average hours</th>
                        <th scope="col" class="text-end">Fastest</th>
                        <th scope="col" class="text-end">Slowest</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['governorate'] }}</td>
                            <td class="text-end">{{ $row['delivered'] }}</td>
                            <td class="text-end">{{ $row['average_hours'] }}</td>
                            <td class="text-end">{{ $row['fastest_hours'] }}</td>
                            <td class="text-end">{{ $row['slowest_hours'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <th scope="row">All governorates</th>
                        <td class="text-end">{{ $delivered }}</td>
                        <td class="text-end">{{ $averageHours }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
@endsection
