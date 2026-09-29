<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Formatting for Blade views. Decimals stay strings from the database to the
 * screen; they are never converted to float, even for display.
 */
final class Display
{
    /** "1600000.5" becomes "1,600,000.50". */
    public static function decimal(string|int $value, int $scale = 2): string
    {
        $fixed = (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp);

        $sign = str_starts_with($fixed, '-') ? '-' : '';
        $parts = explode('.', ltrim($fixed, '-'), 2);
        $grouped = (string) preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);

        return $sign.$grouped.(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    /** A stored UTC instant shown in business (Beirut) time. */
    public static function businessTime(CarbonInterface $instant, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone($timezone ?? (string) config('fleetfuel.business_timezone'))
            ->format('Y-m-d H:i');
    }
}
