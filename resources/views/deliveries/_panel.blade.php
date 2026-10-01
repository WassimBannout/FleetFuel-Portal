{{--
    An order's details, timeline and the next steps this user may take.
    Expects $order with company, creator and statusHistory.changedBy loaded.
    The order page includes it, and resources/js/delivery-actions.js reloads
    it (route deliveries.panel) after every status change or refusal.

    Buttons only show what the policy allows; DeliveryOrderService checks
    everything again with the order locked, so a hidden button is never
    the protection. Every form sends the status shown here as
    expected_status: if the order changed meanwhile, the change is refused
    (409 stale_state) and the panel is reloaded.
--}}
@use('App\Enums\DeliveryStatus')
@use('App\Support\Display')

@php
    $viewer = auth()->user();
    $status = $order->status;
    $advanceTo = $viewer->can('advance', $order)
        ? array_values(array_filter($status->nextStatuses(), fn (DeliveryStatus $next) => $next !== DeliveryStatus::Cancelled))
        : [];
    $canCancel = $viewer->can('cancel', $order);
    $minLocal = now()->setTimezone(config('fleetfuel.business_timezone'))->format('Y-m-d\TH:i');
    $window = fn ($start, $end) => $start === null ? '—' : Display::businessTime($start).' – '.Display::businessTime($end);
@endphp

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h2 class="h5">Order <span data-delivery-status="{{ $status->value }}">@include('partials.delivery-status-badge', ['status' => $status])</span></h2>
                <dl class="row mb-0">
                    <dt class="col-sm-5">Company</dt>
                    <dd class="col-sm-7">{{ $order->company->name }}</dd>
                    <dt class="col-sm-5">Address</dt>
                    <dd class="col-sm-7">{{ $order->address }}</dd>
                    <dt class="col-sm-5">Governorate</dt>
                    <dd class="col-sm-7">{{ $order->governorate }}</dd>
                    <dt class="col-sm-5">Diesel</dt>
                    <dd class="col-sm-7">{{ Display::decimal($order->liters) }} L <span class="small text-body-secondary">(not charged to a fuel card)</span></dd>
                    <dt class="col-sm-5">Preferred window</dt>
                    <dd class="col-sm-7">{{ $window($order->preferred_start_at, $order->preferred_end_at) }}</dd>
                    <dt class="col-sm-5">Scheduled window</dt>
                    <dd class="col-sm-7">{{ $window($order->scheduled_start_at, $order->scheduled_end_at) }}</dd>
                    <dt class="col-sm-5">Truck</dt>
                    <dd class="col-sm-7">{{ $order->assigned_truck ?? '—' }}</dd>
                    @if ($order->delivered_at)
                        <dt class="col-sm-5">Delivered at</dt>
                        <dd class="col-sm-7">{{ Display::businessTime($order->delivered_at) }}</dd>
                    @endif
                    @if ($order->cancel_reason !== null)
                        <dt class="col-sm-5">Cancellation reason</dt>
                        <dd class="col-sm-7">{{ $order->cancel_reason }}</dd>
                    @endif
                </dl>
                <p class="small text-body-secondary mt-2 mb-0">Times are Beirut time.</p>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h2 class="h5">Timeline</h2>
                <ol class="list-unstyled mb-0" data-delivery-timeline>
                    @foreach ($order->statusHistory as $step)
                        <li class="d-flex gap-2 align-items-baseline mb-2">
                            @include('partials.delivery-status-badge', ['status' => $step->to_status])
                            <span>
                                {{ $step->from_status === null ? 'Requested' : ucfirst($step->to_status->label()) }} by {{ $step->changedBy->name }}
                                <span class="d-block small text-body-secondary">{{ Display::businessTime($step->changed_at) }}</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </div>
</div>

@if ($advanceTo !== [] || $canCancel)
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5">Next step</h2>
            <div class="row g-4">
                @foreach ($advanceTo as $next)
                    <div class="col-lg-6">
                        <form method="POST" action="{{ route('deliveries.status', $order) }}" data-delivery-action
                              @if ($next === DeliveryStatus::Delivered) data-delivery-confirm="Mark order #{{ $order->id }} as delivered? This is final." @endif>
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="expected_status" value="{{ $status->value }}">
                            <input type="hidden" name="status" value="{{ $next->value }}">

                            @if ($next === DeliveryStatus::Scheduled)
                                <h3 class="h6">Schedule</h3>
                                <div class="row">
                                    <div class="col-md-6">
                                        <x-form.input type="datetime-local" name="scheduled_start_at" label="Window start" :min="$minLocal" required />
                                    </div>
                                    <div class="col-md-6">
                                        <x-form.input type="datetime-local" name="scheduled_end_at" label="Window end" :min="$minLocal" required />
                                    </div>
                                </div>
                                <x-form.input name="assigned_truck" label="Truck" maxlength="60" required help="For example TRK-04." />
                                <button type="submit" class="btn btn-primary">Schedule delivery</button>
                            @elseif ($next === DeliveryStatus::OutForDelivery)
                                <h3 class="h6">Dispatch</h3>
                                <p class="small text-body-secondary">Truck {{ $order->assigned_truck }} leaves with the diesel.</p>
                                <button type="submit" class="btn btn-primary">Mark out for delivery</button>
                            @else
                                <h3 class="h6">Deliver</h3>
                                <p class="small text-body-secondary">Records the delivery time as now. Delivered orders are final.</p>
                                <button type="submit" class="btn btn-success">Mark delivered</button>
                            @endif
                        </form>
                    </div>
                @endforeach

                @if ($canCancel)
                    <div class="col-lg-6">
                        <form method="POST" action="{{ route('deliveries.status', $order) }}" data-delivery-action
                              data-delivery-confirm="Cancel order #{{ $order->id }}? This is final.">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="expected_status" value="{{ $status->value }}">
                            <input type="hidden" name="status" value="{{ DeliveryStatus::Cancelled->value }}">
                            <h3 class="h6">Cancel</h3>
                            <x-form.input name="reason" label="Reason" maxlength="255" required />
                            <button type="submit" class="btn btn-outline-danger">Cancel order</button>
                        </form>
                    </div>
                @endif
            </div>
        </div>
    </div>
@elseif ($status->isTerminal())
    <p class="text-body-secondary">This order is {{ $status->label() }}. It is final and can no longer change.</p>
@else
    <p class="text-body-secondary">Distributor staff move the order along. It can no longer be cancelled here once scheduled; contact distributor staff instead.</p>
@endif
