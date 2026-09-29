<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /api/v1/cards/{card_no}/balance?month=YYYY-MM. Only the current or
 * the previous Beirut month (a late POS event may still land there), and
 * always measured against the card's current limits.
 */
class CardBalanceRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    public function authorize(): bool
    {
        // Route abilities, the scoped lookup and the policy decide access.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...$this->rejectUnknownFields(),
            function (Validator $validator): void {
                $month = $this->input('month');

                if ($validator->errors()->has('month') || ! is_string($month)) {
                    return;
                }

                if (! in_array($month.'-01', $this->allowedMonths(), true)) {
                    $validator->errors()->add('month', 'Only the current or the previous month (Beirut time) can be requested.');
                }
            },
        ];
    }

    /** First day of the requested Beirut month, "Y-m-d". */
    public function monthStart(): string
    {
        $month = $this->validated('month');

        return is_string($month) ? $month.'-01' : $this->allowedMonths()[0];
    }

    /**
     * @return array{string, string} current, then previous Beirut month
     */
    private function allowedMonths(): array
    {
        $current = BusinessMonth::for(CarbonImmutable::now());

        return [$current, CarbonImmutable::parse($current)->subMonthNoOverflow()->format('Y-m-d')];
    }
}
