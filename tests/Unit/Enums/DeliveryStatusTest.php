<?php

namespace Tests\Unit\Enums;

use App\Enums\DeliveryStatus;
use PHPUnit\Framework\TestCase;

class DeliveryStatusTest extends TestCase
{
    public function test_allowed_transitions_match_the_state_diagram(): void
    {
        $allowed = [
            'pending' => ['scheduled', 'cancelled'],
            'scheduled' => ['out_for_delivery', 'cancelled'],
            'out_for_delivery' => ['delivered', 'cancelled'],
            'delivered' => [],
            'cancelled' => [],
        ];

        foreach (DeliveryStatus::cases() as $from) {
            foreach (DeliveryStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canTransitionTo($to),
                    "{$from->value} -> {$to->value}",
                );
            }
        }
    }

    public function test_only_delivered_and_cancelled_are_terminal(): void
    {
        $terminal = array_filter(DeliveryStatus::cases(), fn (DeliveryStatus $status): bool => $status->isTerminal());

        $this->assertSame([DeliveryStatus::Delivered, DeliveryStatus::Cancelled], array_values($terminal));
    }
}
