<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\FiltersLists;
use App\Http\Requests\Reference\ProductRequest;
use App\Http\Requests\SetActiveRequest;
use App\Models\Product;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The three product codes are fixed (ULP95, ULP98, DIESEL); admins rename
 * or deactivate them. The price timeline arrives with pricing in M04.
 */
class ProductController extends Controller
{
    use FiltersLists;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);

        return view('products.index', [
            'products' => Product::query()->visibleTo($this->actor($request))->orderBy('code')->get(),
        ]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('products.edit', ['product' => $product]);
    }

    public function update(ProductRequest $request, Product $product, ReferenceDataService $reference): RedirectResponse
    {
        $reference->updateProduct($product, (string) $request->validated('name'), $this->actor($request));

        return redirect()->route('products.index')->with('status', "Product {$product->code} saved.");
    }

    public function updateActive(SetActiveRequest $request, Product $product, ReferenceDataService $reference): RedirectResponse
    {
        $product = $reference->setProductActive($product, $request->boolean('is_active'), $this->actor($request));

        return back()->with('status', "Product {$product->code} is now ".($product->is_active ? 'active.' : 'inactive.'));
    }
}
