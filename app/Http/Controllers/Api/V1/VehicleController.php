<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithPages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListVehiclesRequest;
use App\Http\Requests\Api\V1\StoreVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vehicles for admins (every company) and company managers (their own).
 * Creation goes through FleetService, like the web form.
 */
class VehicleController extends Controller
{
    use RespondsWithPages;

    public function index(ListVehiclesRequest $request): JsonResponse
    {
        $page = Vehicle::query()
            ->visibleTo($this->user($request))
            ->when($request->companyFilter(), fn ($query, $company) => $query->where('company_id', $company))
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return $this->pageResponse($page, VehicleResource::class, $request);
    }

    public function store(StoreVehicleRequest $request, FleetService $fleet): JsonResponse
    {
        $vehicle = $fleet->createVehicle($request->company(), $request->validated(), $this->user($request));

        return VehicleResource::make($vehicle)->response()->setStatusCode(201);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
