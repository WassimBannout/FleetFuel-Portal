<?php

namespace App\Http\Resources;

use App\Models\DeliveryStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * docs/api/openapi.json, "DeliveryHistory": one immutable step of an
 * order's timeline. The first step has from_status null.
 *
 * @mixin DeliveryStatusHistory
 */
class DeliveryHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'changed_by' => $this->changed_by,
            'changed_at' => DeliveryOrderResource::instant($this->changed_at),
            'note' => $this->note,
        ];
    }
}
