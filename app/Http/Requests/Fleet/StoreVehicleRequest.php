<?php

namespace App\Http\Requests\Fleet;

use App\Enums\FuelType;
use App\Models\Vehicle;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    use ResolvesCompany;

    public function authorize(): bool
    {
        return $this->actor()->can('create', Vehicle::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('plate_no'))) {
            $this->merge(['plate_no' => Vehicle::normalizePlate($this->input('plate_no'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->companyRules(),
            'plate_no' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9 -]*$/', Rule::unique('vehicles', 'plate_no')],
            'fuel_type' => ['required', Rule::enum(FuelType::class)],
            'tank_capacity_l' => ['required', new DecimalString(8, allowZero: false)],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->companyMessages() + [
            'plate_no.regex' => 'Use letters, digits, spaces and hyphens only.',
        ];
    }
}
