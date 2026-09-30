<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Driver;

/** GET /api/v1/drivers */
class ListDriversRequest extends ListCompanyRecordsRequest
{
    protected function model(): string
    {
        return Driver::class;
    }
}
