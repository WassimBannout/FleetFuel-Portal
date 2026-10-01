<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\DeliveryStatus;
use App\Models\DeliveryOrder;
use Illuminate\Validation\Rule;

/** GET /api/v1/delivery-orders: optional status; company_id for admins only. */
class ListDeliveryOrdersRequest extends ListCompanyRecordsRequest
{
    protected function model(): string
    {
        return DeliveryOrder::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['nullable', 'string', Rule::enum(DeliveryStatus::class)],
        ];
    }

    public function statusFilter(): ?DeliveryStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? DeliveryStatus::from($status) : null;
    }
}
