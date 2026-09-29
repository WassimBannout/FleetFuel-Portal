<?php

namespace App\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Activate or deactivate the record named in the route (station, product,
 * vehicle or driver). The record was already found through its tenant
 * scope; here the policy's `update` ability decides.
 */
class SetActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $record = collect($this->route()?->parameters() ?? [])->first(fn (mixed $value): bool => $value instanceof Model);

        return $record !== null && $this->user()?->can('update', $record) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
