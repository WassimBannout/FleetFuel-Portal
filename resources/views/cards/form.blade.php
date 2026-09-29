@extends('layouts.app')

@use('App\Enums\CardStatus')
@use('App\Enums\CompanyStatus')
@use('App\Support\Display')

@section('title', $card ? 'Change card assignment' : 'Issue card')

@section('content')
    @php
        $used ??= false;
        $locked = $card !== null && ($used || $card->status === CardStatus::Archived);
        $vehicleOptions = $vehicles->mapWithKeys(fn ($vehicle) => [
            $vehicle->id => $vehicle->plate_no.' · '.ucfirst($vehicle->fuel_type->value).' · '.Display::decimal($vehicle->tank_capacity_l).' L'.($vehicle->is_active ? '' : ' (inactive)'),
        ]);
        $driverOptions = $drivers->mapWithKeys(fn ($driver) => [
            $driver->id => $driver->name.' · '.$driver->license_no.($driver->is_active ? '' : ' (inactive)'),
        ]);
        $productOptions = $products->mapWithKeys(fn ($product) => [
            $product->id => $product->name.' ('.$product->code.')'.($product->is_active ? '' : ' (inactive)'),
        ]);
    @endphp

    <h1 class="h3 mb-3">
        @if ($card)
            Change assignment of card <span class="font-monospace">{{ $card->card_no }}</span>
        @else
            Issue a fuel card
        @endif
    </h1>

    <p>
        Company: <strong>{{ $company->name }}</strong>
        @if (! $card && auth()->user()->isAdmin())
            · <a href="{{ route('cards.create') }}">choose another company</a>
        @endif
    </p>

    @if ($company->status !== CompanyStatus::Active)
        <div class="alert alert-warning" role="alert">This company is inactive, so its fleet is read-only.</div>
    @endif
    @if ($card && $used)
        <div class="alert alert-info" role="status">
            This card has already been used, so its vehicle, driver and product restriction are locked.
            Limits and blocking are still available on the <a href="{{ route('cards.show', $card) }}">card page</a>.
        </div>
    @endif

    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ $card ? route('cards.update', $card) : route('cards.store') }}" class="card card-body shadow-sm">
                @csrf
                @if ($card)
                    @method('PUT')
                @elseif (auth()->user()->isAdmin())
                    <input type="hidden" name="company_id" value="{{ $company->id }}">
                @endif

                <fieldset @disabled($locked)>
                    <x-form.select name="vehicle_id" label="Vehicle" :options="$vehicleOptions" :selected="$card?->vehicle_id"
                                   placeholder="No vehicle" help="Optional. The vehicle's fuel type limits which products the card can buy." />
                    @if ($vehicles->isEmpty())
                        <p class="form-text mt-n2">This company has no active vehicles yet. <a href="{{ route('vehicles.create') }}">Add a vehicle</a>, or issue the card without one.</p>
                    @endif

                    <x-form.select name="driver_id" label="Driver" :options="$driverOptions" :selected="$card?->driver_id"
                                   placeholder="No driver" help="Optional." />
                    @if ($drivers->isEmpty())
                        <p class="form-text mt-n2">This company has no active drivers yet. <a href="{{ route('drivers.create') }}">Add a driver</a>, or issue the card without one.</p>
                    @endif

                    <x-form.select name="allowed_product_id" label="Product restriction" :options="$productOptions" :selected="$card?->allowed_product_id"
                                   placeholder="Any product the vehicle can use" />

                    @unless ($card)
                        <div class="row">
                            <div class="col-md-6">
                                <x-form.input name="monthly_limit_l" label="Monthly limit (liters)" inputmode="decimal"
                                              help="Leave empty for no limit; 0 allows no purchases." />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="monthly_limit_usd" label="Monthly limit (USD)" inputmode="decimal"
                                              help="Leave empty for no limit; 0 allows no purchases." />
                            </div>
                        </div>
                    @endunless

                    <div>
                        <button type="submit" class="btn btn-primary">{{ $card ? 'Save assignment' : 'Issue card' }}</button>
                        <a class="btn btn-link" href="{{ $card ? route('cards.show', $card) : route('cards.index') }}">Cancel</a>
                    </div>
                </fieldset>
            </form>
        </div>
    </div>
@endsection
