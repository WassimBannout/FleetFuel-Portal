@extends('layouts.app')

@use('App\Support\Display')

@section('title', 'Consumption report')

@section('content')
    @include('reports._header', ['title' => 'Consumption by '.$groupBy])

    <p class="small">Sums of the liters and amounts stored with each purchase. Nothing is repriced: LBP and USD are what each purchase recorded at its own price and exchange rate.</p>

    @if ($rows === [])
        <p class="text-body-secondary">No purchases in this period.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">{{ ucfirst($groupBy) }}</th>
                        <th scope="col" class="text-end">Purchases</th>
                        <th scope="col" class="text-end">Liters</th>
                        <th scope="col" class="text-end">LBP</th>
                        <th scope="col" class="text-end">USD</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="text-end">{{ $row['purchases'] }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['liters']) }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['amount_lbp']) }}</td>
                            <td class="text-end text-nowrap">{{ Display::decimal($row['amount_usd']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-semibold">
                        <th scope="row">Total</th>
                        <td class="text-end">{{ $totals['purchases'] }}</td>
                        <td class="text-end text-nowrap" data-total="liters">{{ Display::decimal($totals['liters']) }}</td>
                        <td class="text-end text-nowrap" data-total="amount_lbp">{{ Display::decimal($totals['amount_lbp']) }}</td>
                        <td class="text-end text-nowrap" data-total="amount_usd">{{ Display::decimal($totals['amount_usd']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    <h2 class="h5 mt-4">Accounting CSV</h2>
    <p class="small">Every purchase in the period, one row each, with the stored amounts, for the same company scope. The file's totals match the table above.</p>
    <form method="GET" action="{{ route('exports.transactions') }}" class="row g-2 align-items-end">
        <input type="hidden" name="from" value="{{ $firstDay }}">
        <input type="hidden" name="to" value="{{ \Carbon\CarbonImmutable::parse($lastDay)->addDay()->format('Y-m-d') }}">
        @if ($companies !== null && request()->filled('company_id'))
            <input type="hidden" name="company_id" value="{{ request()->integer('company_id') }}">
        @endif
        <div class="col-sm-4 col-lg-3">
            <label for="export-card" class="form-label small mb-1">Card number (optional)</label>
            <input id="export-card" name="card" type="text" maxlength="40" class="form-control form-control-sm" autocomplete="off">
        </div>
        <div class="col-sm-4 col-lg-3">
            <label for="export-station" class="form-label small mb-1">Station</label>
            <select id="export-station" name="station_id" class="form-select form-select-sm">
                <option value="">All stations</option>
                @foreach ($stations as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-4 col-lg-2">
            <label for="export-product" class="form-label small mb-1">Product</label>
            <select id="export-product" name="product_code" class="form-select form-select-sm">
                <option value="">All products</option>
                @foreach ($products as $code)
                    <option value="{{ $code }}">{{ $code }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-primary">Download CSV</button>
        </div>
    </form>
@endsection
