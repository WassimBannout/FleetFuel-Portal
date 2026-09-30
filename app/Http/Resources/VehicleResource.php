<?php

namespace App\Http\Resources;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/api/openapi.json, "Vehicle". The tank capacity is a fixed-scale
 * decimal string.
 *
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'plate_no' => $this->plate_no,
            'fuel_type' => $this->fuel_type->value,
            'tank_capacity_l' => $this->tank_capacity_l,
            'odometer_km' => $this->odometer_km,
            'is_active' => $this->is_active,
        ];
    }
}
