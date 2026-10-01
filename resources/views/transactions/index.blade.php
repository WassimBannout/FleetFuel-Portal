@extends('layouts.app')

@section('title', 'Transactions')

@php
    // Values typed before a refused filter come back as old input with the messages.
    $field = fn (string $name) => (string) old($name, $filterQuery[$name] ?? '');
@endphp

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
        <h1 class="h3 mb-0">Transactions</h1>
        <span class="text-body-secondary">{{ $scopeLabel }}</span>
    </div>
    <p class="small text-body-secondary">
        Accepted POS purchases, newest first. Each amount was fixed with the price and USD/LBP rate in effect
        when the purchase happened; the rate column says where that rate came from. Dates are Beirut calendar days.
    </p>

    {{--
        A plain GET form that works without JavaScript. resources/js/transaction-filters.js
        reloads only the results below when a filter changes and keeps the filters in the address bar.
    --}}
    <form method="GET" action="{{ route('transactions.index') }}" class="row g-2 align-items-start mb-3" role="search"
          aria-label="Filter purchases" novalidate
          data-transaction-filters
          data-results-url="{{ route('transactions.results') }}"
          data-results-target="#transaction-results"
          data-status-target="#transaction-status"
          data-login-url="{{ route('login') }}">
        <div class="col-6 col-md-3 col-xl-2">
            <label for="filter-from" class="form-label small mb-1">From (Beirut date)</label>
            <input id="filter-from" name="from" type="date" value="{{ $field('from') }}"
                   @class(['form-control form-control-sm', 'is-invalid' => $errors->has('from')])
                   @if ($errors->has('from')) aria-invalid="true" aria-describedby="filter-from-error" @endif>
            @error('from')<div id="filter-from-error" class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <label for="filter-to" class="form-label small mb-1">Before (not included)</label>
            <input id="filter-to" name="to" type="date" value="{{ $field('to') }}"
                   @class(['form-control form-control-sm', 'is-invalid' => $errors->has('to')])
                   @if ($errors->has('to')) aria-invalid="true" aria-describedby="filter-to-error" @endif>
            @error('to')<div id="filter-to-error" class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12 col-md-6 col-xl-2">
            <label for="filter-card" class="form-label small mb-1">Card number</label>
            <input id="filter-card" name="card" type="search" value="{{ $field('card') }}" maxlength="40" autocomplete="off" spellcheck="false"
                   @class(['form-control form-control-sm font-monospace', 'is-invalid' => $errors->has('card')])
                   aria-describedby="filter-card-help{{ $errors->has('card') ? ' filter-card-error' : '' }}"
                   @if ($errors->has('card')) aria-invalid="true" @endif>
            <div id="filter-card-help" class="form-text">The full number, e.g. <span class="text-nowrap">FF-ATLAS-001</span>.</div>
            @error('card')<div id="filter-card-error" class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if ($stations !== null)
            <div class="col-6 col-md-4 col-xl-2">
                <label for="filter-station" class="form-label small mb-1">Station</label>
                <select id="filter-station" name="station_id"
                        @class(['form-select form-select-sm', 'is-invalid' => $errors->has('station_id')])
                        @if ($errors->has('station_id')) aria-invalid="true" aria-describedby="filter-station_id-error" @endif>
                    <option value="">All stations</option>
                    @foreach ($stations as $id => $name)
                        <option value="{{ $id }}" @selected($field('station_id') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('station_id')<div id="filter-station_id-error" class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-6 col-md-4 col-xl-2">
            <label for="filter-product" class="form-label small mb-1">Product</label>
            <select id="filter-product" name="product_code"
                    @class(['form-select form-select-sm', 'is-invalid' => $errors->has('product_code')])
                    @if ($errors->has('product_code')) aria-invalid="true" aria-describedby="filter-product_code-error" @endif>
                <option value="">All products</option>
                @foreach ($products as $code => $name)
                    <option value="{{ $code }}" @selected($field('product_code') === (string) $code)>{{ $name }}</option>
                @endforeach
            </select>
            @error('product_code')<div id="filter-product_code-error" class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @if ($companies !== null)
            <div class="col-12 col-md-4 col-xl-2">
                <label for="filter-company" class="form-label small mb-1">Company</label>
                <select id="filter-company" name="company_id"
                        @class(['form-select form-select-sm', 'is-invalid' => $errors->has('company_id')])
                        @if ($errors->has('company_id')) aria-invalid="true" aria-describedby="filter-company_id-error" @endif>
                    <option value="">All companies</option>
                    @foreach ($companies as $id => $name)
                        <option value="{{ $id }}" @selected($field('company_id') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('company_id')<div id="filter-company_id-error" class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endif
        <div class="col-12 col-xl-auto d-flex gap-2 align-self-xl-end">
            <button type="submit" class="btn btn-sm btn-primary">Apply filters</button>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('transactions.index') }}" data-reset>Reset</a>
        </div>
    </form>

    {{-- Screen readers hear "Loading…" and then the result count; the table itself is not re-read. --}}
    <div id="transaction-status" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>

    <div id="transaction-results" tabindex="-1" aria-busy="false">
        @include('transactions._results')
    </div>
@endsection
