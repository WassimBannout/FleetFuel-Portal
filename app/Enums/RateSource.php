<?php

namespace App\Enums;

/**
 * Where a USD/LBP observation came from (docs/04-BUSINESS-RULES.md).
 */
enum RateSource: string
{
    case Provider = 'provider';
    case Manual = 'manual';
    case Fixture = 'fixture';

    /** Screens always say which kind of value a rate is (docs/06-UI-SPEC.md). */
    public function label(): string
    {
        return match ($this) {
            self::Provider => 'Provider',
            self::Manual => 'Manual override',
            self::Fixture => 'Fixture (synthetic)',
        };
    }
}
