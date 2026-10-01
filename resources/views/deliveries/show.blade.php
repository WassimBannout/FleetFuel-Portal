@extends('layouts.app')

@use('App\Support\Display')

@section('title', 'Delivery order #'.$order->id)

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-3">
        <h1 class="h3 mb-0">Delivery order #{{ $order->id }}</h1>
        <a href="{{ route('deliveries.index') }}">All deliveries</a>
    </div>

    {{-- Messages from the status buttons (resources/js/delivery-actions.js). --}}
    <div id="delivery-feedback" aria-live="polite"></div>

    <div id="delivery-panel" data-delivery-panel data-panel-url="{{ route('deliveries.panel', $order) }}" data-feedback="#delivery-feedback"
         data-login-url="{{ route('login') }}">
        @include('deliveries._panel')
    </div>

    @if ($audit !== null)
        <h2 class="h5 mt-4">Audit history</h2>
        @if ($audit->isEmpty())
            <p class="text-body-secondary">No status changes yet.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle bg-body">
                    <thead>
                        <tr>
                            <th scope="col">Time (Beirut)</th>
                            <th scope="col">Change</th>
                            <th scope="col">By</th>
                            <th scope="col">Before</th>
                            <th scope="col">After</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($audit as $entry)
                            <tr>
                                <td class="text-nowrap">{{ $entry->created_at ? Display::businessTime($entry->created_at) : '' }}</td>
                                <td class="font-monospace small">{{ $entry->action }}</td>
                                <td>{{ $entry->user?->name ?? 'Command line / system' }}</td>
                                <td class="font-monospace small">{{ $entry->old_values ? json_encode($entry->old_values) : '—' }}</td>
                                <td class="font-monospace small">{{ $entry->new_values ? json_encode($entry->new_values) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
