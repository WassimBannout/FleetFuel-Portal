<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes LBP-per-liter prices. The timeline is append-only (D06, D15): a
 * price starts now or later, is never edited, and a correction is another
 * price with a later start. Purchases keep the price they snapshotted.
 */
class ProductPriceService
{
    public function __construct(private readonly AuditService $audit) {}

    public function publish(Product $product, string $priceLbp, ?CarbonImmutable $effectiveFrom, User $actor): ProductPrice
    {
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $effectiveFrom = $effectiveFrom?->utc()->startOfSecond() ?? $now;

        if ($effectiveFrom->lessThan($now)) {
            throw ValidationException::withMessages(['effective_from' => 'A new price cannot start in the past.']);
        }

        try {
            return DB::transaction(function () use ($product, $priceLbp, $effectiveFrom, $actor): ProductPrice {
                $price = ProductPrice::query()->forceCreate([
                    'product_id' => $product->id,
                    'price_lbp' => Decimal::normalize($priceLbp, 4),
                    'effective_from' => $effectiveFrom,
                    'created_by' => $actor->id,
                ]);

                $this->audit->record('product_price.published', $price, $actor, null, null, [
                    'product_code' => $product->code,
                    'price_lbp' => $price->price_lbp,
                    'effective_from' => $price->effective_from->toIso8601ZuluString(),
                ]);

                return $price;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['effective_from' => "{$product->code} already has a price starting at that time."]);
        }
    }
}
