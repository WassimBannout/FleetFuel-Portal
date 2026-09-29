@extends('layouts.app')

@section('title', 'Drivers')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Drivers</h1>
        @can('create', App\Models\Driver::class)
            <a class="btn btn-primary" href="{{ route('drivers.create') }}">Add driver</a>
        @endcan
    </div>

    @include('partials.list-filters', [
        'searchLabel' => 'Name or license number',
        'statusOptions' => ['active' => 'Active', 'inactive' => 'Inactive'],
        'companies' => $companies,
    ])

    @if ($drivers->isEmpty())
        <p class="text-body-secondary">No drivers match these filters.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">License</th>
                        <th scope="col">Phone</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($drivers as $driver)
                        <tr>
                            <td>{{ $driver->name }}</td>
                            @if ($companies !== null)
                                <td>{{ $driver->company->name }}</td>
                            @endif
                            <td class="font-monospace">{{ $driver->license_no }}</td>
                            <td>{{ $driver->phone ?? '—' }}</td>
                            <td>@include('partials.active-badge', ['active' => $driver->is_active])</td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('drivers.edit', $driver) }}">Edit<span class="visually-hidden"> {{ $driver->name }}</span></a>
                                @include('partials.active-toggle', ['action' => route('drivers.active', $driver), 'active' => $driver->is_active, 'name' => $driver->name])
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $drivers->links() }}
    @endif
@endsection
