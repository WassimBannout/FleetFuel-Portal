<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Station;

/** GET /api/v1/stations: active stations, optionally one governorate. */
class ListStationsRequest extends PaginatedListRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Station::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'governorate' => ['nullable', 'string', 'max:80'],
            ...$this->paginationRules(),
        ];
    }
}
