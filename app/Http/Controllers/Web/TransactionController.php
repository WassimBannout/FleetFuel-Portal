<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FuelTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TransactionController extends Controller
{
    /**
     * One immutable purchase with its price and exchange-rate snapshot.
     */
    public function show(Request $request, string $transaction): View
    {
        /** @var User $user */
        $user = $request->user();

        // 1. Scope before lookup: another company's (or station's) purchase
        //    is "not found" (404), exactly like an ID that does not exist,
        //    so guessing IDs reveals nothing.
        $record = FuelTransaction::query()
            ->visibleTo($user)
            ->with(['company', 'station', 'product', 'fuelCard', 'vehicle', 'driver'])
            ->findOrFail((int) $transaction);

        // 2. The policy is a second, independent check of the same rule.
        Gate::authorize('view', $record);

        return view('transactions.show', ['transaction' => $record]);
    }
}
