<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Concerns\FiltersTransactions;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The accounting CSV download: the transaction list's own filters and
 * rules (FiltersTransactions), with no pagination.
 */
class ExportTransactionsRequest extends FormRequest
{
    use FiltersTransactions;

    public function authorize(): bool
    {
        return $this->user()?->can('exportTransactions') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->transactionFilterRules();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->dateRangeLimit()];
    }

    public function filename(): string
    {
        [$first, $last] = $this->businessDates();

        return "fleetfuel-transactions-{$first}-to-{$last}.csv";
    }

    public function actor(): User
    {
        $user = $this->user();
        assert($user instanceof User);

        return $user;
    }
}
