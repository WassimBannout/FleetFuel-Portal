@extends('layouts.app')

@use('App\Enums\DeliveryStatus')
@use('App\Support\Display')

@section('title', 'Diesel deliveries')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Diesel deliveries</h1>
        @can('create', App\Models\DeliveryOrder::class)
            <a class="btn btn-primary" href="{{ route('deliveries.create') }}">Request delivery</a>
        @endcan
    </div>

    @include('partials.list-filters', [
        'searchLabel' => 'Address, governorate or truck',
        'statusOptions' => collect(DeliveryStatus::cases())->mapWithKeys(fn ($status) => [$status->value => ucfirst($status->label())])->all(),
        'companies' => $companies,
    ])

    <p class="small text-body-secondary">Times are Beirut time. Delivered diesel is not charged to any fuel card.</p>

    @if ($orders->isEmpty())
        <p class="text-body-secondary">No delivery orders match these filters.</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle bg-body">
                <thead>
                    <tr>
                        <th scope="col">Order</th>
                        @if ($companies !== null)
                            <th scope="col">Company</th>
                        @endif
                        <th scope="col">Address</th>
                        <th scope="col" class="text-end">Liters</th>
                        <th scope="col">Preferred window</th>
                        <th scope="col">Status</th>
                        <th scope="col">Truck</th>
                        <th scope="col">Requested</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><a href="{{ route('deliveries.show', $order) }}">#{{ $order->id }}</a></td>
                            @if ($companies !== null)
                                <td>{{ $order->company->name }}</td>
                            @endif
                            <td>
                                {{ $order->address }}
                                <span class="d-block small text-body-secondary">{{ $order->governorate }}</span>
                            </td>
                            <td class="text-end text-nowrap">{{ Display::decimal($order->liters) }}</td>
                            <td class="text-nowrap">{{ Display::businessTime($order->preferred_start_at) }} – {{ Display::businessTime($order->preferred_end_at) }}</td>
                            <td>@include('partials.delivery-status-badge', ['status' => $order->status])</td>
                            <td>{{ $order->assigned_truck ?? '—' }}</td>
                            <td class="text-nowrap">{{ Display::businessTime($order->created_at) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @endif
@endsection
