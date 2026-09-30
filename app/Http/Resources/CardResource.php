<?php

namespace App\Http\Resources;

use App\Models\FuelCard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/api/openapi.json, "Card". Limits are fixed-scale decimal strings;
 * null means unlimited.
 *
 * @mixin FuelCard
 */
class CardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'vehicle_id' => $this->vehicle_id,
            'driver_id' => $this->driver_id,
            'card_no' => $this->card_no,
            'allowed_product_id' => $this->allowed_product_id,
            'monthly_limit_l' => $this->monthly_limit_l,
            'monthly_limit_usd' => $this->monthly_limit_usd,
            'status' => $this->status->value,
        ];
    }
}
