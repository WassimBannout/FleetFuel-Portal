<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPricesRequest;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\PriceResolver;
use App\Support\FuelAmounts;
use Illuminate\Http\JsonResponse;

/**
 * The price list at an instant: each active product's LBP price per liter
 * and an indicative USD price at the exchange rate in effect then. It uses
 * PriceResolver, like a purchase, and only reads stored rows.
 */
class ProductPriceController extends Controller
{
    private const UTC = 'Y-m-d\TH:i:s\Z';

    public function index(ListPricesRequest $request, PriceResolver $resolver): JsonResponse
    {
        $at = $request->instant();

        // Same order as a purchase: a missing price is 422, then a missing
        // rate 503. Nothing is ever shown at a zero or guessed value.
        $prices = Product::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(fn (Product $product): array => [$product, $resolver->priceAt($product, $at)]);

        $rate = $resolver->rateAt($at);

        return response()->json([
            'data' => $prices->map(fn (array $pair): array => $this->row($pair[0], $pair[1], $rate))->values(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function row(Product $product, ProductPrice $price, ExchangeRate $rate): array
    {
        return [
            'product_code' => $product->code,
            'unit' => $product->unit,
            'unit_price_lbp' => $price->price_lbp,
            'indicative_unit_price_usd' => FuelAmounts::indicativeUnitPriceUsd($price->price_lbp, $rate->rate),
            'effective_from' => $price->effective_from->utc()->format(self::UTC),
            'rate_effective_at' => $rate->effective_at->utc()->format(self::UTC),
            'rate_source' => $rate->source->value,
        ];
    }
}
