<?php

namespace App\Http\Controllers\Web;

use App\Enums\CompanyStatus;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\FiltersLists;
use App\Http\Requests\Deliveries\StoreDeliveryOrderRequest;
use App\Http\Requests\Deliveries\TransitionDeliveryRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Services\DeliveryOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Diesel delivery orders: managers request them for their own company and
 * may cancel them while pending; admins schedule, dispatch, deliver or
 * cancel them. Every change goes through DeliveryOrderService, the same
 * code as the API. The order page's status buttons submit with jQuery
 * (JSON) and fall back to ordinary form posts without JavaScript.
 */
class DeliveryOrderController extends Controller
{
    use FiltersLists;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', DeliveryOrder::class);
        $user = $this->actor($request);

        $query = DeliveryOrder::query()->visibleTo($user)->with('company');
        $this->applySearch($query, $this->searchTerm($request), ['address', 'governorate', 'assigned_truck']);
        $this->applyCompanyFilter($query, $request, $user);

        $status = DeliveryStatus::tryFrom($request->string('status')->toString());
        if ($status !== null) {
            $query->where('status', $status);
        }

        return view('deliveries.index', [
            'orders' => $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'companies' => $this->companyOptions($user),
        ]);
    }

    /**
     * An admin first picks the (active) company; a manager's company is
     * always their own and a ?company= parameter is ignored.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', DeliveryOrder::class);
        $user = $this->actor($request);

        $company = $user->isAdmin()
            ? Company::query()->where('status', CompanyStatus::Active)->find($request->integer('company') ?: null)
            : Company::query()->findOrFail($user->company_id);

        if ($company === null) {
            return view('deliveries.choose-company', ['companies' => $this->companyOptions($user, activeOnly: true)]);
        }

        return view('deliveries.form', ['company' => $company]);
    }

    public function store(StoreDeliveryOrderRequest $request, DeliveryOrderService $deliveries): RedirectResponse
    {
        $order = $deliveries->create($request->company(), $request->details(), $this->actor($request));

        return redirect()->route('deliveries.show', $order)->with('status', "Delivery order #{$order->id} requested. It is pending until distributor staff schedule it.");
    }

    public function show(Request $request, DeliveryOrder $delivery): View
    {
        Gate::authorize('view', $delivery);
        $user = $this->actor($request);

        return view('deliveries.show', [
            'order' => $this->withTimeline($delivery),
            'audit' => $user->can('viewAny', AuditLog::class)
                ? AuditLog::query()->visibleTo($user)->with('user')
                    ->where('auditable_type', $delivery->getMorphClass())
                    ->where('auditable_id', $delivery->id)
                    ->orderByDesc('id')
                    ->get()
                : null,
        ]);
    }

    /** The status, timeline and next actions alone, for the page script to refresh. */
    public function panel(DeliveryOrder $delivery): View
    {
        Gate::authorize('view', $delivery);

        return view('deliveries._panel', ['order' => $this->withTimeline($delivery)]);
    }

    public function updateStatus(TransitionDeliveryRequest $request, DeliveryOrder $delivery, DeliveryOrderService $deliveries): JsonResponse|RedirectResponse
    {
        $order = $deliveries->transition(
            $delivery,
            $request->expectedStatus(),
            $request->targetStatus(),
            $request->details(),
            $this->actor($request),
        );

        $message = match ($order->status) {
            DeliveryStatus::Scheduled => "Order #{$order->id} scheduled.",
            DeliveryStatus::OutForDelivery => "Order #{$order->id} is out for delivery.",
            DeliveryStatus::Delivered => "Order #{$order->id} delivered.",
            DeliveryStatus::Cancelled => "Order #{$order->id} cancelled.",
            DeliveryStatus::Pending => "Order #{$order->id} is pending.",
        };

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'status' => $order->status->value]);
        }

        return redirect()->route('deliveries.show', $order)->with('status', $message);
    }

    private function withTimeline(DeliveryOrder $order): DeliveryOrder
    {
        return $order->load(['company', 'creator', 'statusHistory.changedBy']);
    }
}
