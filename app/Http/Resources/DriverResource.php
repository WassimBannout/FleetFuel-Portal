<?php

namespace App\Http\Resources;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/api/openapi.json, "Driver". Only the owning company's manager and
 * admins can reach it (fleet:read plus the tenant scope).
 *
 * @mixin Driver
 */
class DriverResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'license_no' => $this->license_no,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
        ];
    }
}
