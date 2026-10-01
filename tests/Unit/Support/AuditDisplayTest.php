<?php

namespace Tests\Unit\Support;

use App\Support\AuditDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuditDisplayTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function values(): array
    {
        return [
            'password' => ['password', 'hunter2', '[redacted]'],
            'remember token' => ['remember_token', 'abc', '[redacted]'],
            'api token' => ['token', 'plain-token', '[redacted]'],
            'secret' => ['client_secret', 's3cr3t', '[redacted]'],
            'a count of revoked tokens is not a secret' => ['tokens_revoked', 3, '3'],
            'card number masked' => ['card_no', 'FF-ATLAS-001', '••••-001'],
            'limit' => ['monthly_limit_l', '200.00', '200.00'],
            'null' => ['vehicle_id', null, 'none'],
            'true' => ['is_active', true, 'yes'],
            'false' => ['is_active', false, 'no'],
            'nested values as JSON' => ['window', ['start' => '2026-09-01T08:00:00Z'], '{"start":"2026-09-01T08:00:00Z"}'],
        ];
    }

    #[DataProvider('values')]
    public function test_values_are_shown_masked_or_redacted(string $field, mixed $value, string $shown): void
    {
        $this->assertSame($shown, AuditDisplay::value($field, $value));
    }

    public function test_changes_list_every_field_from_either_side(): void
    {
        $this->assertSame([
            ['field' => 'status', 'before' => 'pending', 'after' => 'scheduled'],
            ['field' => 'assigned_truck', 'before' => null, 'after' => 'TRK-01'],
        ], AuditDisplay::changes(['status' => 'pending'], ['status' => 'scheduled', 'assigned_truck' => 'TRK-01']));

        $this->assertSame([['field' => 'is_active', 'before' => null, 'after' => 'yes']], AuditDisplay::changes(null, ['is_active' => true]));
        $this->assertSame([], AuditDisplay::changes(null, null));
    }

    public function test_actions_and_record_types_read_as_words(): void
    {
        $this->assertSame('Card limits changed', AuditDisplay::action('card.limits_changed'));
        $this->assertSame('Delivery order status changed', AuditDisplay::action('delivery_order.status_changed'));
        $this->assertSame('Fuel card', AuditDisplay::entityLabel('fuel_card'));
        $this->assertSame('Something new', AuditDisplay::entityLabel('something_new'));
        $this->assertArrayHasKey('delivery_order', AuditDisplay::entityOptions());
    }
}
