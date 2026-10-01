<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithPages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListDeliveryOrdersRequest;
use App\Http\Requests\Api\V1\StoreDeliveryOrderRequest;
use App\Http\Requests\Api\V1\TransitionDeliveryRequest;
use App\Http\Resources\DeliveryOrderResource;
use App\Models\DeliveryOrder;
use App\Models\User;
use App\Services\DeliveryOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Diesel delivery orders for admins (every company) and company managers
 * (their own). {delivery} is looked up through the caller's tenant scope,
 * so another company's order is a 404. Creation and status changes go
 * through DeliveryOrderService, like the web screens.
 */
class DeliveryOrderController extends Controller
{
    use RespondsWithPages;

    public function index(ListDeliveryOrdersRequest $request): JsonResponse
    {
        $page = DeliveryOrder::query()
            ->visibleTo($this->user($request))
            ->when($request->companyFilter(), fn ($query, $company) => $query->where('company_id', $company))
            ->when($request->statusFilter(), fn ($query, $status) => $query->where('status', $status))
            ->with('statusHistory')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return $this->pageResponse($page, DeliveryOrderResource::class, $request);
    }

    public function store(StoreDeliveryOrderRequest $request, DeliveryOrderService $deliveries): JsonResponse
    {
        $order = $deliveries->create($request->company(), $request->details(), $this->user($request));

        return DeliveryOrderResource::make($order)
            ->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.delivery-orders.show', $order, absolute: false));
    }

    public function show(DeliveryOrder $delivery): DeliveryOrderResource
    {
        Gate::authorize('view', $delivery);

        return DeliveryOrderResource::make($delivery->load('statusHistory'));
    }

    public function updateStatus(TransitionDeliveryRequest $request, DeliveryOrder $delivery, DeliveryOrderService $deliveries): DeliveryOrderResource
    {
        return DeliveryOrderResource::make($deliveries->transition(
            $delivery,
            $request->expectedStatus(),
            $request->targetStatus(),
            $request->details(),
            $this->user($request),
        ));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
