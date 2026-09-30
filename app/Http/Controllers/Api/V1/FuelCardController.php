<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateCardRequest;
use App\Http\Resources\CardResource;
use App\Models\FuelCard;
use App\Models\User;
use App\Services\FuelCardService;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /api/v1/cards/{id}. The route's role check stops station operators
 * (403) before {card} is looked up, and the lookup goes through the
 * caller's tenant scope, so another company's card is 404.
 */
class FuelCardController extends Controller
{
    public function update(UpdateCardRequest $request, FuelCard $card, FuelCardService $cards): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return CardResource::make($cards->applyChanges($card, $request->changes(), $user))->response();
    }
}
