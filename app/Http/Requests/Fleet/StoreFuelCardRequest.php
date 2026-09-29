<?php

namespace App\Http\Requests\Fleet;

use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\Product;
use App\Models\Vehicle;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Issue a card. Vehicle and driver must be active records of the owning
 * company: an ID from another company fails validation, exactly like an ID
 * that does not exist. (The composite foreign keys are the last line.)
 */
class StoreFuelCardRequest extends FormRequest
{
    use ResolvesCompany;

    public function authorize(): bool
    {
        return $this->actor()->can('create', FuelCard::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->targetCompanyId();

        return [
            'company_id' => $this->companyRules(),
            'vehicle_id' => ['nullable', 'integer',
                Rule::exists('vehicles', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'driver_id' => ['nullable', 'integer',
                Rule::exists('drivers', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'allowed_product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('is_active', true)],
            // DECIMAL(12,2) and DECIMAL(18,2); empty means unlimited.
            'monthly_limit_l' => ['nullable', new DecimalString(10)],
            'monthly_limit_usd' => ['nullable', new DecimalString(16)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->companyMessages() + [
            'vehicle_id.exists' => 'Choose an active vehicle of this company.',
            'driver_id.exists' => 'Choose an active driver of this company.',
            'allowed_product_id.exists' => 'Choose an active product.',
        ];
    }

    public function vehicle(): ?Vehicle
    {
        $id = $this->validated('vehicle_id');

        return $id === null ? null : Vehicle::query()->where('company_id', $this->company()->id)->findOrFail((int) $id);
    }

    public function driver(): ?Driver
    {
        $id = $this->validated('driver_id');

        return $id === null ? null : Driver::query()->where('company_id', $this->company()->id)->findOrFail((int) $id);
    }

    public function product(): ?Product
    {
        $id = $this->validated('allowed_product_id');

        return $id === null ? null : Product::query()->findOrFail((int) $id);
    }
}
