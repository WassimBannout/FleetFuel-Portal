@extends('layouts.app')

@section('title', $driver ? 'Edit driver' : 'Add driver')

@section('content')
    <h1 class="h3 mb-3">{{ $driver ? 'Edit driver '.$driver->name : 'Add driver' }}</h1>

    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ $driver ? route('drivers.update', $driver) : route('drivers.store') }}" class="card card-body shadow-sm">
                @csrf
                @if ($driver)
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

                <x-form.input name="name" label="Name" :value="$driver?->name" required maxlength="120" />
                <div class="row">
                    <div class="col-md-6">
                        <x-form.input name="license_no" label="License number" :value="$driver?->license_no" required maxlength="50"
                                      help="Unique within the company." />
                    </div>
                    <div class="col-md-6">
                        <x-form.input name="phone" label="Phone" type="tel" :value="$driver?->phone" maxlength="30" help="Optional." />
                    </div>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a class="btn btn-link" href="{{ route('drivers.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
