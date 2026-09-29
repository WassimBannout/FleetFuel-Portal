<?php

namespace App\Exceptions;

use App\Models\Product;

/**
 * No price of the product is effective at the event time. The purchase or
 * preview fails; a zero or guessed price is never used (422).
 */
class PriceUnavailable extends ApiException
{
    public static function for(Product $product): self
    {
        return new self(422, 'price_unavailable', "No {$product->code} price is effective at that time.", ['product_code' => $product->code]);
    }
}
