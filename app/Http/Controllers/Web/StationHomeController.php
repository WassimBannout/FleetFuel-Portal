<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FuelTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Station operator landing page: the latest purchases at their own station
 * and how to obtain an API token for the POS. The station comes from the
 * signed-in account, never from the URL.
 */
class StationHomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('station.home', [
            'user' => $user->loadMissing('station'),
            'purchases' => FuelTransaction::query()
                ->visibleTo($user)
                ->with(['company', 'product', 'fuelCard'])
                ->orderByDesc('transacted_at')
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
        ]);
    }
}
