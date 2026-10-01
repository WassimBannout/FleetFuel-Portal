<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transactions\ListTransactionsRequest;
use App\Models\Company;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TransactionController extends Controller
{
    /**
     * The purchase list: filters, totals of the whole filter, and one page
     * of rows. Works as a plain GET form; page scripts reload only the
     * results through results().
     */
    public function index(ListTransactionsRequest $request): View
    {
        $user = $request->actor();

        return view('transactions.index', $this->listing($request) + [
            'scopeLabel' => match (true) {
                $user->isAdmin() => 'All companies and stations',
                $user->isCompanyManager() => $user->loadMissing('company')->company?->name,
                default => $user->loadMissing('station')->station?->name,
            },
            // Choices for the filters, each scoped like the list itself.
            'companies' => $user->isAdmin() ? Company::query()->orderBy('name')->pluck('name', 'id') : null,
            'stations' => $user->isStationOperator() ? null : Station::query()->visibleTo($user)->orderBy('name')->pluck('name', 'id'),
            'products' => Product::query()->visibleTo($user)->orderBy('name')->pluck('name', 'code'),
        ]);
    }

    /**
     * The same listing as index(), for page scripts: the results as HTML
     * rendered (and escaped) by Blade, a sentence for screen readers, and
     * the address of this view for the browser history.
     */
    public function results(ListTransactionsRequest $request): JsonResponse
    {
        $listing = $this->listing($request);
        $page = $listing['purchases']->currentPage();

        return response()->json([
            'html' => view('transactions._results', $listing)->render(),
            'summary' => $listing['summary'],
            'url' => route('transactions.index', $listing['filterQuery'] + ($page > 1 ? ['page' => (string) $page] : []), false),
            'from' => $listing['filterQuery']['from'],
            'to' => $listing['filterQuery']['to'],
        ]);
    }

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

    /**
     * The scoped, filtered ledger. A fixed number of queries whatever the
     * page size: one aggregate (the totals of the whole filter and the row
     * count the pager needs), one page of rows, and one query per eager-
     * loaded relation. The filters are applied only here, on the server;
     * the browser never filters or sums rows itself.
     *
     * @return array<string, mixed>
     */
    private function listing(ListTransactionsRequest $request): array
    {
        $user = $request->actor();
        $query = $request->transactionFilters()->apply(FuelTransaction::query()->visibleTo($user));

        $totals = (clone $query)->toBase()
            ->selectRaw('COUNT(*) AS purchases')
            ->selectRaw('COALESCE(SUM(liters), 0) AS liters')
            ->selectRaw('COALESCE(SUM(amount_lbp), 0) AS amount_lbp')
            ->selectRaw('COALESCE(SUM(amount_usd), 0) AS amount_usd')
            ->first();
        $count = (int) ($totals->purchases ?? 0);
        $filterQuery = $request->filterQuery();

        // The known count is passed to the pager, so it runs no COUNT query of its own.
        $purchases = $query
            ->with(['company', 'station', 'product', 'fuelCard'])
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->paginate(ListTransactionsRequest::PER_PAGE, ['*'], 'page', null, $count)
            ->withPath(route('transactions.index'))
            ->appends($filterQuery);

        [$first, $last] = $request->businessDates();

        return [
            'purchases' => $purchases,
            'totals' => [
                'purchases' => $count,
                'liters' => (string) ($totals->liters ?? '0'),
                'amount_lbp' => (string) ($totals->amount_lbp ?? '0'),
                'amount_usd' => (string) ($totals->amount_usd ?? '0'),
            ],
            'summary' => match (true) {
                $count === 0 => 'No purchases match these filters.',
                $purchases->isEmpty() => 'This page is past the last page of results.',
                $count === 1 => 'Showing the only matching purchase.',
                default => "Showing {$purchases->firstItem()}–{$purchases->lastItem()} of {$count} purchases.",
            },
            'filterQuery' => $filterQuery,
            'firstDay' => $first,
            'lastDay' => $last,
            'csvUrl' => $user->can('exportTransactions') ? route('exports.transactions', $filterQuery) : null,
            'showCompany' => ! $user->isCompanyManager(),
            'showStation' => ! $user->isStationOperator(),
        ];
    }
}
