@extends('layouts.app')

@section('title', 'Request delivery')

@section('content')
    @php
        // Suggestions only; any governorate name up to 80 characters is accepted.
        $governorates = ['Akkar', 'Baalbek-Hermel', 'Beirut', 'Bekaa', 'Keserwan-Jbeil', 'Mount Lebanon', 'Nabatieh', 'North', 'South'];
        $minLocal = now()->setTimezone(config('fleetfuel.business_timezone'))->format('Y-m-d\TH:i');
    @endphp

    <h1 class="h3 mb-3">Request a diesel delivery</h1>

    <p>
        Company: <strong>{{ $company->name }}</strong>
        @if (auth()->user()->isAdmin())
            · <a href="{{ route('deliveries.create') }}">choose another company</a>
        @endif
    </p>

    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('deliveries.store') }}" class="card card-body shadow-sm">
                @csrf
                @if (auth()->user()->isAdmin())
                    <input type="hidden" name="company_id" value="{{ $company->id }}">
                @endif
                @error('company_id')
                    <div class="alert alert-danger" role="alert">{{ $message }}</div>
                @enderror

                <div class="mb-3">
                    <label for="address" class="form-label">Delivery address<span class="text-danger" aria-hidden="true"> *</span></label>
                    <textarea id="address" name="address" rows="2" maxlength="500" required
                              @class(['form-control', 'is-invalid' => $errors->has('address')])
                              @error('address') aria-describedby="address-error" @enderror>{{ old('address') }}</textarea>
                    @error('address')
                        <div id="address-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <x-form.input name="governorate" label="Governorate" list="governorate-options" maxlength="80" required />
                <datalist id="governorate-options">
                    @foreach ($governorates as $governorate)
                        <option value="{{ $governorate }}"></option>
                    @endforeach
                </datalist>

                <x-form.input name="liters" label="Diesel (liters)" inputmode="decimal" required
                              help="Up to two decimal places. Deliveries are not charged to a fuel card." />

                <div class="row">
                    <div class="col-md-6">
                        <x-form.input type="datetime-local" name="preferred_start_at" label="Preferred from (Beirut time)" :min="$minLocal" required />
                    </div>
                    <div class="col-md-6">
                        <x-form.input type="datetime-local" name="preferred_end_at" label="Preferred until (Beirut time)" :min="$minLocal" required />
                    </div>
                </div>
                <p class="form-text mt-0">The window must start in the future. Distributor staff confirm the actual window and truck when they schedule the order.</p>

                <div>
                    <button type="submit" class="btn btn-primary">Request delivery</button>
                    <a class="btn btn-link" href="{{ route('deliveries.index') }}">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
