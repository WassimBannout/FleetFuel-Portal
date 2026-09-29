<?php

namespace App\Http\Requests\Reference;

use App\Models\Station;
use Illuminate\Foundation\Http\FormRequest;

/** Create or edit a station (admin). Activation uses SetActiveRequest. */
class StationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $station = $this->route('station');

        return $station instanceof Station
            ? $this->user()?->can('update', $station) === true
            : $this->user()?->can('create', Station::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'district' => ['required', 'string', 'max:80'],
            'governorate' => ['required', 'string', 'max:80'],
            // DECIMAL(10,7) columns with CHECK constraints on the same ranges.
            'latitude' => ['nullable', 'numeric', 'decimal:0,7', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'decimal:0,7', 'between:-180,180'],
        ];
    }
}
