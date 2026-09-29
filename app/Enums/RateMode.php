<?php

namespace App\Enums;

use RuntimeException;

/**
 * Which USD/LBP observations the application trusts (EXCHANGE_RATE_MODE).
 *
 * - Fixture: synthetic observations for demos and tests; no HTTP request.
 * - Live: the external provider's observations; fixture rows are ignored.
 *
 * Manual admin overrides apply in both modes. A live deployment never falls
 * back to fixtures when the provider fails (docs/04-BUSINESS-RULES.md).
 */
enum RateMode: string
{
    case Fixture = 'fixture';
    case Live = 'live';

    public static function current(): self
    {
        $configured = config('fleetfuel.exchange_rates.mode');

        return self::tryFrom(is_string($configured) ? $configured : '')
            ?? throw new RuntimeException('EXCHANGE_RATE_MODE must be "fixture" or "live".');
    }

    /** The automated source this mode reads, besides manual overrides. */
    public function source(): RateSource
    {
        return match ($this) {
            self::Fixture => RateSource::Fixture,
            self::Live => RateSource::Provider,
        };
    }

    /** Key of this mode's row in integration_sync_states. */
    public function syncStateName(): string
    {
        return 'exchange_rates.'.$this->value;
    }
}
