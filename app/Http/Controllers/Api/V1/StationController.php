<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithPages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListStationsRequest;
use App\Http\Resources\StationResource;
use App\Models\Station;
use Illuminate\Http\JsonResponse;

/**
 * Reference data for API clients: the stations where cards can be used.
 * Only active stations are listed, for every role (admins manage inactive
 * ones on the web screens).
 */
class StationController extends Controller
{
    use RespondsWithPages;

    public function index(ListStationsRequest $request): JsonResponse
    {
        $page = Station::query()
            ->where('is_active', true)
            ->when($request->validated('governorate'), fn ($query, $governorate) => $query->where('governorate', $governorate))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return $this->pageResponse($page, StationResource::class, $request);
    }
}
