@extends('layouts.app')

@section('title', $station ? 'Edit station' : 'Add station')

@section('content')
    <h1 class="h3 mb-3">{{ $station ? 'Edit '.$station->name : 'Add station' }}</h1>

    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ $station ? route('stations.update', $station) : route('stations.store') }}" class="card card-body shadow-sm">
                @csrf
                @if ($station)
                    @method('PUT')
                @endif

                <x-form.input name="name" label="Name" :value="$station?->name" required maxlength="120" />
                <div class="row">
                    <div class="col-md-6">
                        <x-form.input name="district" label="District" :value="$station?->district" required maxlength="80" />
                    </div>
                    <div class="col-md-6">
                        <x-form.input name="governorate" label="Governorate" :value="$station?->governorate" required maxlength="80" />
                    </div>
                    <div class="col-md-6">
                        <x-form.input name="latitude" label="Latitude" :value="$station?->latitude" inputmode="decimal" help="Optional, up to 7 decimals." />
                    </div>
                    <div class="col-md-6">
                        <x-form.input name="longitude" label="Longitude" :value="$station?->longitude" inputmode="decimal" help="Optional, up to 7 decimals." />
                    </div>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a class="btn btn-link" href="{{ route('stations.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
