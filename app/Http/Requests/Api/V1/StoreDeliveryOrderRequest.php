<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Http\Requests\Deliveries\StoreDeliveryOrderRequest as WebStoreDeliveryOrderRequest;
use App\Rules\OffsetTimestamp;
use Carbon\CarbonImmutable;

/**
 * POST /api/v1/delivery-orders: the web form's rules plus the API
 * conventions. Unknown fields (status, assigned_truck, delivered_at, a
 * price...) are refused, company_id must be a JSON integer (admins only),
 * and times are offset-bearing ISO 8601 instants with whole seconds.
 */
class StoreDeliveryOrderRequest extends WebStoreDeliveryOrderRequest
{
    use RejectsUnknownFields;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'company_id' => $this->companyRules(jsonInteger: true),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function timeRules(): array
    {
        return [new OffsetTimestamp];
    }

    protected function time(string $key): CarbonImmutable
    {
        $time = OffsetTimestamp::parse($this->validated($key));
        assert($time !== null);

        return $time;
    }
}
