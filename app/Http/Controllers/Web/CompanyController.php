<?php

namespace App\Http\Controllers\Web;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\FiltersLists;
use App\Http\Requests\Reference\CompanyRequest;
use App\Http\Requests\Reference\CompanyStatusRequest;
use App\Models\Company;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Admin-only (route middleware and CompanyPolicy). Companies are deactivated, never deleted. */
class CompanyController extends Controller
{
    use FiltersLists;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Company::class);

        $query = Company::query()->visibleTo($this->actor($request))
            ->withCount(['vehicles', 'drivers', 'fuelCards']);
        $this->applySearch($query, $this->searchTerm($request), ['name', 'tax_no']);

        $status = CompanyStatus::tryFrom($request->string('status')->toString());
        if ($status !== null) {
            $query->where('status', $status);
        }

        return view('companies.index', [
            'companies' => $query->orderBy('name')->orderBy('id')->paginate(25)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Company::class);

        return view('companies.form', ['company' => null]);
    }

    public function store(CompanyRequest $request, ReferenceDataService $reference): RedirectResponse
    {
        $company = $reference->createCompany($request->validated(), $this->actor($request));

        return redirect()->route('companies.index')->with('status', "Company {$company->name} added.");
    }

    public function edit(Company $company): View
    {
        Gate::authorize('update', $company);

        return view('companies.form', ['company' => $company->loadCount(['vehicles', 'drivers', 'fuelCards'])]);
    }

    public function update(CompanyRequest $request, Company $company, ReferenceDataService $reference): RedirectResponse
    {
        $reference->updateCompany($company, $request->validated(), $this->actor($request));

        return redirect()->route('companies.index')->with('status', "Company {$company->name} saved.");
    }

    public function updateStatus(CompanyStatusRequest $request, Company $company, ReferenceDataService $reference): RedirectResponse
    {
        $company = $reference->setCompanyStatus($company, CompanyStatus::from((string) $request->validated('status')), $this->actor($request));

        return back()->with('status', "Company {$company->name} is now {$company->status->value}.");
    }
}
