<?php

namespace App\Http\Requests\Reference;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or edit a company (admin). Status changes use CompanyStatusRequest. */
class CompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            ? $this->user()?->can('update', $company) === true
            : $this->user()?->can('create', Company::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'name' => ['required', 'string', 'max:120'],
            'tax_no' => ['nullable', 'string', 'max:50',
                Rule::unique('companies', 'tax_no')->ignore($company instanceof Company ? $company->id : null)],
        ];
    }
}
