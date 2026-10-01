<?php

namespace App\Http\Resources;

use App\Models\DeliveryOrder;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/api/openapi.json, "Delivery": the order with its chronological
 * status history. Liters are a fixed-scale decimal string and instants are
 * UTC with a Z suffix. Callers eager-load statusHistory.
 *
 * @mixin DeliveryOrder
 */
class DeliveryOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'address' => $this->address,
            'governorate' => $this->governorate,
            'liters' => $this->liters,
            'preferred_start_at' => self::instant($this->preferred_start_at),
            'preferred_end_at' => self::instant($this->preferred_end_at),
            'scheduled_start_at' => self::instant($this->scheduled_start_at),
            'scheduled_end_at' => self::instant($this->scheduled_end_at),
            'assigned_truck' => $this->assigned_truck,
            'status' => $this->status->value,
            'delivered_at' => self::instant($this->delivered_at),
            'cancel_reason' => $this->cancel_reason,
            'history' => DeliveryHistoryResource::collection($this->statusHistory)->resolve($request),
        ];
    }

    /** "2026-09-30T06:00:00Z", or null. */
    public static function instant(?CarbonInterface $value): ?string
    {
        return $value?->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
