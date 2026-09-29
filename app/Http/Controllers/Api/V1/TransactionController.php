<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListTransactionsRequest;
use App\Http\Requests\Api\V1\StorePosTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\FuelTransaction;
use App\Models\User;
use App\Services\FuelTransactionService;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POS ingestion and the scoped, immutable purchase ledger.
 */
class TransactionController extends Controller
{
    /**
     * 201 for a new purchase (with Location), 200 with
     * Idempotency-Replayed: true for an identical retry.
     */
    public function store(StorePosTransactionRequest $request, FuelTransactionService $transactions): JsonResponse
    {
        $result = $transactions->ingest($this->user($request), $request->purchase());
        $transaction = $result->transaction->loadMissing(['fuelCard', 'product']);
        $response = TransactionResource::make($transaction)->response();

        return $result->replayed
            ? $response->setStatusCode(200)->header('Idempotency-Replayed', 'true')
            : $response->setStatusCode(201)->header('Location', route('api.v1.transactions.show', $transaction, absolute: false));
    }

    /**
     * Scoped purchases, newest first, with totals for the whole filter
     * (not just the current page).
     */
    public function index(ListTransactionsRequest $request): JsonResponse
    {
        [$from, $to] = $request->utcRange();

        $query = FuelTransaction::query()
            ->visibleTo($this->user($request))
            ->where('transacted_at', '>=', $from)
            ->where('transacted_at', '<', $to)
            ->when($request->validated('card'), fn ($q, $card) => $q->whereHas('fuelCard', fn ($c) => $c->where('card_no', strtoupper((string) $card))))
            ->when($request->validated('station_id'), fn ($q, $station) => $q->where('station_id', (int) $station))
            ->when($request->validated('company_id'), fn ($q, $company) => $q->where('company_id', (int) $company))
            ->when($request->validated('product_code'), fn ($q, $code) => $q->whereHas('product', fn ($p) => $p->where('code', $code)));

        $totals = (clone $query)->toBase()
            ->selectRaw('SUM(liters) AS liters, SUM(amount_lbp) AS amount_lbp, SUM(amount_usd) AS amount_usd')
            ->first();

        $page = $query->with(['fuelCard', 'product'])
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return response()->json([
            'data' => TransactionResource::collection($page->getCollection())->resolve($request),
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'totals' => [
                    'liters' => Decimal::normalize((string) ($totals->liters ?? '0')),
                    'amount_lbp' => Decimal::normalize((string) ($totals->amount_lbp ?? '0')),
                    'amount_usd' => Decimal::normalize((string) ($totals->amount_usd ?? '0')),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $transaction): JsonResponse
    {
        // Scope before lookup: another company's or station's purchase is
        // "not found", like an ID that does not exist.
        $record = FuelTransaction::query()
            ->visibleTo($this->user($request))
            ->with(['fuelCard', 'product'])
            ->findOrFail((int) $transaction);

        Gate::authorize('view', $record);

        return TransactionResource::make($record)->response();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
