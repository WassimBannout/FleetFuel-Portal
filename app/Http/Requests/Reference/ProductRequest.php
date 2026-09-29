<?php

namespace App\Http\Requests\Reference;

use Illuminate\Foundation\Http\FormRequest;

/** Rename a product (admin). The code and fuel type never change. */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'code' => ['prohibited'],
            'fuel_type' => ['prohibited'],
        ];
    }
}
