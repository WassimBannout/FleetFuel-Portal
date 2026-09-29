<?php

namespace App\Http\Requests\Fleet;

use App\Models\Driver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDriverRequest extends FormRequest
{
    use ResolvesCompany;

    public function authorize(): bool
    {
        return $this->actor()->can('create', Driver::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('license_no'))) {
            $this->merge(['license_no' => Driver::normalizeLicense($this->input('license_no'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->companyRules(),
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+() -]+$/'],
            // Unique within the company only (UNIQUE (company_id, license_no)).
            'license_no' => ['required', 'string', 'max:50',
                Rule::unique('drivers', 'license_no')->where('company_id', $this->targetCompanyId())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->companyMessages() + [
            'phone.regex' => 'Use digits, spaces, +, - and brackets only.',
            'license_no.unique' => 'This company already has a driver with this license number.',
        ];
    }
}
