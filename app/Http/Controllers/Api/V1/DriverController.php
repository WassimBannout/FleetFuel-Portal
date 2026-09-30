<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithPages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListDriversRequest;
use App\Http\Requests\Api\V1\StoreDriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\Driver;
use App\Models\User;
use App\Services\FleetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drivers for admins (every company) and company managers (their own).
 * Creation goes through FleetService, like the web form.
 */
class DriverController extends Controller
{
    use RespondsWithPages;

    public function index(ListDriversRequest $request): JsonResponse
    {
        $page = Driver::query()
            ->visibleTo($this->user($request))
            ->when($request->companyFilter(), fn ($query, $company) => $query->where('company_id', $company))
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return $this->pageResponse($page, DriverResource::class, $request);
    }

    public function store(StoreDriverRequest $request, FleetService $fleet): JsonResponse
    {
        $driver = $fleet->createDriver($request->company(), $request->validated(), $this->user($request));

        return DriverResource::make($driver)->response()->setStatusCode(201);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
