<?php

namespace App\Enums;

/**
 * Delivery order states and the allowed transitions from the state diagram in
 * docs/04-BUSINESS-RULES.md. Who may perform a transition is decided elsewhere.
 */
enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->nextStatuses(), true);
    }

    public function isTerminal(): bool
    {
        return $this->nextStatuses() === [];
    }

    /**
     * @return list<self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Pending => [self::Scheduled, self::Cancelled],
            self::Scheduled => [self::OutForDelivery, self::Cancelled],
            self::OutForDelivery => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }
}
