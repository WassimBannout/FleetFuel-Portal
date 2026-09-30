<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

/**
 * Two-decimal amounts ("20.00" liters) as whole hundredths, so balances are
 * compared exactly. The API sends decimals as strings; they are never
 * turned into floats here.
 */
final class Cents
{
    /** "20" -> 2000, "20.5" -> 2050, "20.05" -> 2005. */
    public static function parse(string $amount): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts) !== 1) {
            throw new UnexpectedOutcome("Expected a decimal string with at most 2 places, got \"{$amount}\".");
        }

        return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');
    }

    /** 2000 -> "20.00" */
    public static function format(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The shortest equivalent spelling: "20.00" -> "20", "20.50" -> "20.5". */
    public static function shortest(string $amount): string
    {
        return str_contains($amount, '.') ? rtrim(rtrim($amount, '0'), '.') : $amount;
    }
}
