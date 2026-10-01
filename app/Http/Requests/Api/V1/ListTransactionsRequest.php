<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\FiltersTransactions;
use App\Models\FuelTransaction;
use Illuminate\Validation\Validator;

/**
 * GET /api/v1/transactions filters (docs/05-API-CONTRACT.md). Dates are
 * Beirut calendar days: `from` inclusive, `to` exclusive, at most 366 days,
 * defaulting to the current Beirut month. Only an admin may filter by
 * company; for everyone else ownership comes from the account. The CSV
 * export uses the same filters (FiltersTransactions).
 */
class ListTransactionsRequest extends PaginatedListRequest
{
    use FiltersTransactions;

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
            ...$this->paginationRules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [...parent::after(), $this->dateRangeLimit()];
    }
}
