@extends('layouts.app')

@use('App\Support\Display')

@section('title', 'Vehicles')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Vehicles</h1>
        @can('create', App\Models\Vehicle::class)
            <a class="btn btn-primary" href="{{ route('vehicles.create') }}">Add vehicle</a>
        @endcan
    </div>

    @include('partials.list-filters', [
        'searchLabel' => 'Plate number',
        'statusOptions' => ['active' => 'Active', 'inactive' => 'Inactive'],
        'companies' => $companies,
    ])

    @if ($vehicles->isEmpty())
        <p class="text-body-secondary">No vehicles match these filters.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Plate</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">Fuel</th>
                        <th scope="col" class="text-end">Tank (L)</th>
                        <th scope="col" class="text-end">Odometer (km)</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($vehicles as $vehicle)
                        <tr>
                            <td class="font-monospace">{{ $vehicle->plate_no }}</td>
                            @if ($companies !== null)
                                <td>{{ $vehicle->company->name }}</td>
                            @endif
                            <td>{{ ucfirst($vehicle->fuel_type->value) }}</td>
                            <td class="text-end">{{ Display::decimal($vehicle->tank_capacity_l) }}</td>
                            <td class="text-end">{{ $vehicle->odometer_km !== null ? number_format($vehicle->odometer_km) : '—' }}</td>
                            <td>@include('partials.active-badge', ['active' => $vehicle->is_active])</td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('vehicles.edit', $vehicle) }}">Edit<span class="visually-hidden"> {{ $vehicle->plate_no }}</span></a>
                                @include('partials.active-toggle', ['action' => route('vehicles.active', $vehicle), 'active' => $vehicle->is_active, 'name' => $vehicle->plate_no])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $vehicles->links() }}
    @endif
@endsection
