<?php

namespace App\Http\Requests\Fleet;

use App\Models\User;
use App\Models\Vehicle;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Company and fuel type are fixed after creation, so sending them is an error. */
class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('update', $this->vehicle());
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
            'company_id' => ['prohibited'],
            'fuel_type' => ['prohibited'],
            'plate_no' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9 -]*$/',
                Rule::unique('vehicles', 'plate_no')->ignore($this->vehicle()->id)],
            'tank_capacity_l' => ['required', new DecimalString(8, allowZero: false)],
            'odometer_km' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate_no.regex' => 'Use letters, digits, spaces and hyphens only.',
            'company_id.prohibited' => 'A vehicle cannot move to another company.',
            'fuel_type.prohibited' => 'The fuel type is fixed when the vehicle is created.',
        ];
    }

    public function vehicle(): Vehicle
    {
        $vehicle = $this->route('vehicle');
        assert($vehicle instanceof Vehicle);

        return $vehicle;
    }
}
