<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\FiltersLists;
use App\Http\Requests\Reference\StoreProductPriceRequest;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\PriceResolver;
use App\Services\ProductPriceService;
use App\Support\Display;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * A product's immutable LBP-per-liter price timeline. Every role reads it
 * (non-admins only for active products, through the scoped {product}
 * binding); only an admin publishes a new price.
 */
class ProductPriceController extends Controller
{
    use FiltersLists;

    public function index(Product $product, PriceResolver $resolver): View
    {
        Gate::authorize('viewAny', ProductPrice::class);

        $now = CarbonImmutable::now();

        return view('products.prices', [
            'product' => $product,
            'prices' => $product->prices()->with('creator')->orderByDesc('effective_from')->paginate(20),
            'current' => $resolver->findPrice($product, $now),
            'rate' => $resolver->findRate($now),
            'now' => $now,
        ]);
    }

    public function store(StoreProductPriceRequest $request, Product $product, ProductPriceService $prices): RedirectResponse
    {
        $price = $prices->publish($product, $request->priceLbp(), $request->effectiveFrom(), $this->actor($request));

        return redirect()->route('products.prices.index', $product)->with(
            'status',
            "Published {$product->code} at ".Display::decimal($price->price_lbp, 4).' LBP per liter from '.Display::businessTime($price->effective_from).' (Beirut time).',
        );
    }
}
