<?php

namespace App\Http\Requests\Transactions;

use App\Http\Requests\Concerns\FiltersTransactions;
use App\Models\FuelTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Filters of the purchase list screen and its AJAX results: the same rules
 * as the API list and the accounting CSV (FiltersTransactions), so the
 * screen's totals and the CSV of the same filter cover the same rows.
 *
 * A full page with invalid filters returns to the unfiltered list with the
 * messages; the AJAX results get a 422 with field errors.
 */
class ListTransactionsRequest extends FormRequest
{
    use FiltersTransactions;

    public const PER_PAGE = 25;

    protected $redirectRoute = 'transactions.index';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FuelTransaction::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->transactionFilterRules(),
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->dateRangeLimit()];
    }

    /**
     * The applied filters as query parameters, built from the same
     * TransactionFilters the query uses, without empty values or the page.
     * It is the address of this view and the CSV link of the same rows. The
     * dates are always explicit, so a CSV downloaded on another day still
     * covers the period shown.
     *
     * @return array<string, string>
     */
    public function filterQuery(): array
    {
        $filters = $this->transactionFilters();
        [$first, $last] = $this->businessDates();

        return array_filter([
            'from' => $first,
            'to' => CarbonImmutable::parse($last)->addDay()->format('Y-m-d'),
            'card' => $filters->cardNo,
            'station_id' => $filters->stationId === null ? null : (string) $filters->stationId,
            'product_code' => $filters->productCode,
            'company_id' => $filters->companyId === null ? null : (string) $filters->companyId,
        ], fn (?string $value): bool => $value !== null);
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }
}
