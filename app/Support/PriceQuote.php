<?php

namespace App\Support;

use App\Models\ExchangeRate;
use App\Models\ProductPrice;

/**
 * The price, rate and rounded amounts for one purchase at one instant: the
 * values a POS transaction snapshots (M05). All amounts are decimal strings.
 */
final readonly class PriceQuote
{
    public function __construct(
        public ProductPrice $price,
        public ExchangeRate $rate,
        public string $liters,
        public string $amountLbp,
        public string $amountUsd,
    ) {}
}
