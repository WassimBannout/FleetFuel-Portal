<?php

namespace App\Support;

use Brick\Math\BigDecimal;

/**
 * Small helpers for validated decimal strings (see App\Rules\DecimalString).
 */
final class Decimal
{
    /** "150" becomes "150.00". Null stays null, which means "unlimited" for quotas. */
    public static function normalize(?string $value, int $scale = 2): ?string
    {
        return $value === null ? null : (string) BigDecimal::of($value)->toScale($scale);
    }

    public static function isLessThan(string $value, string $than): bool
    {
        return BigDecimal::of($value)->isLessThan($than);
    }
}
