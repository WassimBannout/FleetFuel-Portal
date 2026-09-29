<?php

namespace App\Http\Resources;

use App\Models\FuelTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An accepted purchase as the API returns it (docs/api/openapi.json,
 * "Transaction"): amounts are fixed-scale decimal strings and instants are
 * UTC with a Z. Expects fuelCard and product to be loaded.
 *
 * @mixin FuelTransaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_ref' => $this->external_ref,
            'station_id' => $this->station_id,
            'company_id' => $this->company_id,
            'card_no' => $this->fuelCard->card_no,
            'product_code' => $this->product->code,
            'liters' => $this->liters,
            'unit_price_lbp' => $this->unit_price_lbp,
            'amount_lbp' => $this->amount_lbp,
            'amount_usd' => $this->amount_usd,
            'rate_lbp_per_usd' => $this->rate_lbp_per_usd,
            'rate_source' => $this->rate_source->value,
            'rate_effective_at' => $this->rate_effective_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'transacted_at' => $this->transacted_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'quota_month' => $this->quota_month->format('Y-m-d'),
            'odometer_km' => $this->odometer_km,
        ];
    }
}
