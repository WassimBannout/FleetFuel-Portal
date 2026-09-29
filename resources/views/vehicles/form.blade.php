@extends('layouts.app')

@section('title', $vehicle ? 'Edit vehicle' : 'Add vehicle')

@section('content')
    <h1 class="h3 mb-3">{{ $vehicle ? 'Edit vehicle '.$vehicle->plate_no : 'Add vehicle' }}</h1>

    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ $vehicle ? route('vehicles.update', $vehicle) : route('vehicles.store') }}" class="card card-body shadow-sm">
                @csrf
                @if ($vehicle)
                    @method('PUT')
                @endif

                @if ($companies !== null)
                    @if ($companies->isEmpty())
                        <div class="alert alert-warning">There is no active company yet. <a href="{{ route('companies.create') }}">Add a company</a> first.</div>
                    @endif
                    <x-form.select name="company_id" label="Company" :options="$companies" placeholder="Choose a company" required />
                @else
                    <p class="mb-3">Company: <strong>{{ $ownCompany?->name }}</strong></p>
                @endif

                <x-form.input name="plate_no" label="Plate number" :value="$vehicle?->plate_no" required maxlength="30"
                              help="Letters, digits, spaces and hyphens. Stored in capitals." />

                @if ($vehicle)
                    <p class="mb-3">Fuel type: <strong>{{ ucfirst($vehicle->fuel_type->value) }}</strong> (fixed when the vehicle was added)</p>
                @else
                    <x-form.select name="fuel_type" label="Fuel type" :options="['diesel' => 'Diesel', 'petrol' => 'Petrol']" placeholder="Choose a fuel type" required />
                @endif

                <div class="row">
                    <div class="col-md-6">
                        <x-form.input name="tank_capacity_l" label="Tank capacity (L)" :value="$vehicle?->tank_capacity_l" required inputmode="decimal"
                                      help="For example 60 or 60.5." />
                    </div>
                    <div class="col-md-6">
                        <x-form.input name="odometer_km" label="Odometer (km)" type="number" min="0" step="1" :value="$vehicle?->odometer_km" help="Optional." />
                    </div>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a class="btn btn-link" href="{{ route('vehicles.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
