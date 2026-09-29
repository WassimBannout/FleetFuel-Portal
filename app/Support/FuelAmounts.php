<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Purchase arithmetic from docs/04-BUSINESS-RULES.md, on decimal strings:
 *
 *   amount_lbp = round_half_up(liters × unit_price_lbp, 2)
 *   amount_usd = round_half_up(amount_lbp ÷ rate_lbp_per_usd, 2)
 *
 * USD is derived from the rounded LBP amount, never from a rounded per-liter
 * USD price, and no PHP float is involved. This class only calculates:
 * PriceResolver::quote() validates the input and checks the column bounds.
 */
final class FuelAmounts
{
    public static function amountLbp(string $liters, string $unitPriceLbp): string
    {
        return (string) BigDecimal::of($liters)
            ->multipliedBy($unitPriceLbp)
            ->toScale(2, RoundingMode::HalfUp);
    }

    public static function amountUsd(string $amountLbp, string $rateLbpPerUsd): string
    {
        return (string) BigDecimal::of($amountLbp)
            ->dividedBy($rateLbpPerUsd, 2, RoundingMode::HalfUp);
    }

    /**
     * Indicative USD per liter for price lists, to 4 decimals. Display only:
     * a purchase's USD amount comes from its rounded LBP amount instead.
     */
    public static function indicativeUnitPriceUsd(string $unitPriceLbp, string $rateLbpPerUsd): string
    {
        return (string) BigDecimal::of($unitPriceLbp)
            ->dividedBy($rateLbpPerUsd, 4, RoundingMode::HalfUp);
    }

    /**
     * Exact sum of two amounts with the given scale. Throws instead of
     * rounding if an operand has more decimal places than the scale.
     */
    public static function add(string $a, string $b, int $scale = 2): string
    {
        return (string) BigDecimal::of($a)->plus($b)->toScale($scale);
    }
}
