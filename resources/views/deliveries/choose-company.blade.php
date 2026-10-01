@extends('layouts.app')

@section('title', 'Request delivery')

@section('content')
    <h1 class="h3 mb-3">Request a diesel delivery</h1>

    <div class="row">
        <div class="col-lg-6">
            <form method="GET" action="{{ route('deliveries.create') }}" class="card card-body shadow-sm">
                <p>First choose the company the diesel is for.</p>

                @if ($companies->isEmpty())
                    <div class="alert alert-warning">There is no active company yet. <a href="{{ route('companies.create') }}">Add a company</a> first.</div>
                @endif

                <x-form.select name="company" label="Company" :options="$companies" placeholder="Choose a company" required />

                <div>
                    <button type="submit" class="btn btn-primary">Continue</button>
                    <a class="btn btn-link" href="{{ route('deliveries.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
