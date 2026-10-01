<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ConsumptionReportRequest;
use App\Repositories\ReportRepository;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/reports/consumption: liters and stored amounts per company,
 * vehicle or product for the caller's scope (docs/api/openapi.json,
 * "Consumption"). Unpaginated: there is one row per group.
 */
class ReportController extends Controller
{
    public function consumption(ConsumptionReportRequest $request, ReportRepository $reports): JsonResponse
    {
        $rows = $reports->consumption($request->scope(), $request->groupBy());

        return response()->json([
            'data' => array_map(fn (array $row): array => [
                'group_id' => $row['group_id'],
                'label' => $row['label'],
                'liters' => $row['liters'],
                'amount_lbp' => $row['amount_lbp'],
                'amount_usd' => $row['amount_usd'],
            ], $rows),
        ]);
    }
}
