<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\FiltersLists;
use App\Http\Requests\Reference\StationRequest;
use App\Http\Requests\SetActiveRequest;
use App\Models\Station;
use App\Services\ReferenceDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Every role can read the station list (non-admins see active stations
 * only); only admins create, edit or deactivate stations.
 */
class StationController extends Controller
{
    use FiltersLists;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Station::class);
        $user = $this->actor($request);

        $query = Station::query()->visibleTo($user);
        $this->applySearch($query, $this->searchTerm($request), ['name', 'district', 'governorate']);
        if ($user->isAdmin()) {
            $this->applyActiveFilter($query, $request);
        }

        return view('stations.index', [
            'stations' => $query->orderBy('name')->orderBy('id')->paginate(25)->withQueryString(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Station::class);

        return view('stations.form', ['station' => null]);
    }

    public function store(StationRequest $request, ReferenceDataService $reference): RedirectResponse
    {
        $station = $reference->createStation($request->validated(), $this->actor($request));

        return redirect()->route('stations.index')->with('status', "Station {$station->name} added.");
    }

    public function edit(Station $station): View
    {
        Gate::authorize('update', $station);

        return view('stations.form', ['station' => $station]);
    }

    public function update(StationRequest $request, Station $station, ReferenceDataService $reference): RedirectResponse
    {
        $reference->updateStation($station, $request->validated(), $this->actor($request));

        return redirect()->route('stations.index')->with('status', "Station {$station->name} saved.");
    }

    public function updateActive(SetActiveRequest $request, Station $station, ReferenceDataService $reference): RedirectResponse
    {
        $station = $reference->setStationActive($station, $request->boolean('is_active'), $this->actor($request));

        return back()->with('status', "Station {$station->name} is now ".($station->is_active ? 'active.' : 'inactive.'));
    }
}
