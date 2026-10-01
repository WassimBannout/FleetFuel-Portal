<?php

namespace App\Http\Requests\Concerns;

use App\Enums\ProductCode;
use App\Models\User;
use App\Support\TransactionFilters;
use Illuminate\Validation\Rule;

/**
 * The ledger filters of docs/05-API-CONTRACT.md ("Common filters on
 * transactions/CSV"): dates, card number, station, product, and company
 * for admins only. A manager's company_id is refused even when it names
 * their own company, because ownership comes from the account.
 */
trait FiltersTransactions
{
    use FiltersBusinessDates;

    /**
     * @return array<string, mixed>
     */
    protected function transactionFilterRules(): array
    {
        $user = $this->user();

        return [
            ...$this->dateRangeRules(),
            'card' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'station_id' => ['nullable', 'integer', 'min:1'],
            'product_code' => ['nullable', 'string', Rule::enum(ProductCode::class)],
            'company_id' => $user instanceof User && $user->isAdmin() ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
        ];
    }

    public function transactionFilters(): TransactionFilters
    {
        [$from, $to] = $this->utcRange();
        $card = $this->validated('card');
        $station = $this->validated('station_id');
        $company = $this->validated('company_id');
        $product = $this->validated('product_code');

        return new TransactionFilters(
            $from,
            $to,
            is_string($card) ? strtoupper($card) : null,
            $station === null ? null : (int) $station,
            $company === null ? null : (int) $company,
            is_string($product) ? $product : null,
        );
    }
}
