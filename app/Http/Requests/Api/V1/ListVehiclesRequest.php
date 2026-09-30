<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Vehicle;

/** GET /api/v1/vehicles */
class ListVehiclesRequest extends ListCompanyRecordsRequest
{
    protected function model(): string
    {
        return Vehicle::class;
    }
}
