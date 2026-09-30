<?php

namespace App\Http\Resources;

use App\Models\Station;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A station summary (docs/api/openapi.json, "Station"). Coordinates are
 * left out: the list is for choosing a station, not for mapping.
 *
 * @mixin Station
 */
class StationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'district' => $this->district,
            'governorate' => $this->governorate,
            'is_active' => $this->is_active,
        ];
    }
}
