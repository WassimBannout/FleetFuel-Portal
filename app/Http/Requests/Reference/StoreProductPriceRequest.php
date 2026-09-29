<?php

namespace App\Http\Requests\Reference;

use App\Http\Requests\Concerns\ParsesBusinessTime;
use App\Models\ProductPrice;
use App\Rules\DecimalString;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publish a new price for the product in the URL (admin). The start time is
 * empty for "now" or a later Beirut time; ProductPriceService refuses the
 * past, because published history is never rewritten.
 */
class StoreProductPriceRequest extends FormRequest
{
    use ParsesBusinessTime;

    public function authorize(): bool
    {
        return $this->user()?->can('create', ProductPrice::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // price_lbp is DECIMAL(18,4) with a positive CHECK.
            'price_lbp' => ['required', new DecimalString(14, 4, allowZero: false)],
            'effective_from' => ['nullable', $this->businessTimeRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['price_lbp' => 'price (LBP per liter)', 'effective_from' => 'start time'];
    }

    public function priceLbp(): string
    {
        return (string) $this->validated('price_lbp');
    }

    public function effectiveFrom(): ?CarbonImmutable
    {
        return $this->businessTime('effective_from');
    }
}
