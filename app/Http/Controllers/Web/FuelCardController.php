<?php

namespace App\Http\Controllers\Web;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\FiltersLists;
use App\Http\Requests\Fleet\ChangeCardStatusRequest;
use App\Http\Requests\Fleet\StoreFuelCardRequest;
use App\Http\Requests\Fleet\UpdateCardAssignmentRequest;
use App\Http\Requests\Fleet\UpdateCardLimitsRequest;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Vehicle;
use App\Services\FuelCardService;
use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Fuel cards. Writes go through FuelCardService (card lock + audit), the
 * same service the API card endpoint will use in M06.
 */
class FuelCardController extends Controller
{
    use FiltersLists;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', FuelCard::class);
        $user = $this->actor($request);
        $month = BusinessMonth::for(CarbonImmutable::now());

        $query = FuelCard::query()->visibleTo($user)->with([
            'company', 'vehicle', 'driver', 'allowedProduct',
            'monthlyUsages' => fn ($usage) => $usage->where('month_start', $month),
        ]);
        $term = $this->searchTerm($request);
        $this->applySearch($query, $term === null ? null : strtoupper($term), ['card_no']);
        $this->applyCompanyFilter($query, $request, $user);

        $status = CardStatus::tryFrom($request->string('status')->toString());
        if ($status !== null) {
            $query->where('status', $status);
        }

        return view('cards.index', [
            'cards' => $query->orderBy('card_no')->paginate(25)->withQueryString(),
            'month' => $month,
            'companies' => $this->companyOptions($user),
        ]);
    }

    /**
     * An admin first picks the (active) company; a manager's company is
     * always their own and a ?company= parameter is ignored.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', FuelCard::class);
        $user = $this->actor($request);

        $company = $user->isAdmin()
            ? Company::query()->where('status', CompanyStatus::Active)->find($request->integer('company') ?: null)
            : Company::query()->findOrFail($user->company_id);

        if ($company === null) {
            return view('cards.choose-company', ['companies' => $this->companyOptions($user, activeOnly: true)]);
        }

        return view('cards.form', ['card' => null, 'company' => $company] + $this->assignmentOptions($company));
    }

    public function store(StoreFuelCardRequest $request, FuelCardService $cards): RedirectResponse
    {
        $card = $cards->create(
            $request->company(),
            $request->vehicle(),
            $request->driver(),
            $request->product(),
            $request->validated('monthly_limit_l'),
            $request->validated('monthly_limit_usd'),
            $this->actor($request),
        );

        return redirect()->route('cards.show', $card)->with('status', "Card {$card->card_no} issued.");
    }

    public function show(Request $request, FuelCard $card, FuelCardService $cards): View
    {
        Gate::authorize('view', $card);
        $user = $this->actor($request);

        return view('cards.show', [
            'card' => $card->loadMissing(['company', 'vehicle', 'driver', 'allowedProduct']),
            'balance' => $cards->balance($card),
            'used' => $card->transactions()->exists(),
            'recent' => FuelTransaction::query()
                ->visibleTo($user)
                ->where('fuel_card_id', $card->id)
                ->with(['company', 'station', 'product', 'fuelCard'])
                ->orderByDesc('transacted_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'audit' => $user->can('viewAny', AuditLog::class)
                ? AuditLog::query()->visibleTo($user)->with('user')
                    ->where('auditable_type', $card->getMorphClass())
                    ->where('auditable_id', $card->id)
                    ->orderByDesc('id')
                    ->limit(20)
                    ->get()
                : null,
        ]);
    }

    public function edit(FuelCard $card): View
    {
        Gate::authorize('update', $card);
        $company = Company::query()->findOrFail($card->company_id);

        return view('cards.form', [
            'card' => $card,
            'company' => $company,
            'used' => $card->transactions()->exists(),
        ] + $this->assignmentOptions($company, $card));
    }

    public function update(UpdateCardAssignmentRequest $request, FuelCard $card, FuelCardService $cards): RedirectResponse
    {
        $cards->updateAssignment($card, $request->vehicle(), $request->driver(), $request->product(), $this->actor($request));

        return redirect()->route('cards.show', $card)->with('status', 'Assignment saved.');
    }

    public function updateLimits(UpdateCardLimitsRequest $request, FuelCard $card, FuelCardService $cards): RedirectResponse
    {
        $cards->updateLimits($card, $request->limitL(), $request->limitUsd(), $this->actor($request),
            allowBelowUsage: $request->boolean('confirm_below_usage'));

        return redirect()->route('cards.show', $card)->with('status', 'Monthly limits saved.');
    }

    public function updateStatus(ChangeCardStatusRequest $request, FuelCard $card, FuelCardService $cards): RedirectResponse
    {
        $card = $cards->changeStatus($card, $request->status(), $this->actor($request));

        return redirect()->route('cards.show', $card)->with('status', match ($card->status) {
            CardStatus::Active => 'Card unblocked.',
            CardStatus::Blocked => 'Card blocked. POS purchases with it will be declined.',
            CardStatus::Archived => 'Card archived. It can no longer be used or changed; its history is kept.',
        });
    }

    /**
     * Dropdown choices for a card of $company: its active vehicles and
     * drivers and the active products, plus whatever is currently assigned.
     * These are the same limits the Form Requests enforce.
     *
     * @return array<string, mixed>
     */
    private function assignmentOptions(Company $company, ?FuelCard $card = null): array
    {
        $activeOrCurrent = fn (?int $currentId) => fn (Builder $query) => $query
            ->where('is_active', true)
            ->when($currentId !== null, fn (Builder $inner) => $inner->orWhere('id', $currentId));

        return [
            'vehicles' => Vehicle::query()->where('company_id', $company->id)
                ->where($activeOrCurrent($card?->vehicle_id))->orderBy('plate_no')->get(),
            'drivers' => Driver::query()->where('company_id', $company->id)
                ->where($activeOrCurrent($card?->driver_id))->orderBy('name')->get(),
            'products' => Product::query()->where($activeOrCurrent($card?->allowed_product_id))->orderBy('code')->get(),
        ];
    }
}
