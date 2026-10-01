{{-- Expects $status (App\Enums\DeliveryStatus). --}}
@php
    $deliveryBadgeClass = match ($status) {
        App\Enums\DeliveryStatus::Pending => 'text-bg-warning',
        App\Enums\DeliveryStatus::Scheduled => 'text-bg-info',
        App\Enums\DeliveryStatus::OutForDelivery => 'text-bg-primary',
        App\Enums\DeliveryStatus::Delivered => 'text-bg-success',
        App\Enums\DeliveryStatus::Cancelled => 'text-bg-secondary',
    };
@endphp
<span class="badge {{ $deliveryBadgeClass }}">{{ ucfirst($status->label()) }}</span>
