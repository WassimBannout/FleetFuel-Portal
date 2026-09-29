<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CardBalanceRequest;
use App\Models\FuelCard;
use App\Models\User;
use App\Services\FuelCardService;
use App\Support\BusinessMonth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A card's usage and remaining quota for one Beirut month, measured against
 * its current limits. Informational only: it reserves nothing, and only
 * POST /transactions checks and consumes quota atomically. It names no
 * company, vehicle or driver.
 */
class CardBalanceController extends Controller
{
    public function show(CardBalanceRequest $request, string $cardNo, FuelCardService $cards): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        // A card can be used at any station, so an operator may look any
        // card up; a manager only finds their own company's cards (404).
        $card = ($user->isStationOperator() ? FuelCard::query() : FuelCard::query()->visibleTo($user))
            ->where('card_no', strtoupper($cardNo))
            ->firstOrFail();

        Gate::authorize('viewBalance', $card);

        $balance = $cards->balance($card, BusinessMonth::startUtc($request->monthStart()));

        return response()->json(['data' => [
            'card_no' => $card->card_no,
            'month' => substr($balance->month, 0, 7),
            'status' => $card->status->value,
            'monthly_limit_l' => $balance->limitL,
            'monthly_limit_usd' => $balance->limitUsd,
            'used_l' => $balance->usedL,
            'used_usd' => $balance->usedUsd,
            'remaining_l' => $balance->remainingL(),
            'remaining_usd' => $balance->remainingUsd(),
            'over_quota' => $balance->isOverQuota(),
        ]]);
    }
}
