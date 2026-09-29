<?php

namespace App\Http\Requests\Reference;

use App\Http\Requests\Concerns\ParsesBusinessTime;
use App\Models\ExchangeRate;
use App\Rules\DecimalString;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin's manual USD/LBP override: positive rate, a reason, a start of
 * now or later, and at most 72 hours of validity. ExchangeRateService
 * checks the time rules again when it writes the row.
 */
class StoreExchangeRateOverrideRequest extends FormRequest
{
    use ParsesBusinessTime;

    public function authorize(): bool
    {
        return $this->user()?->can('create', ExchangeRate::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // exchange_rates.rate is DECIMAL(20,8) with a positive CHECK.
            'rate' => ['required', new DecimalString(12, 8, allowZero: false)],
            'reason' => ['required', 'string', 'max:255'],
            'valid_for_hours' => ['required', 'integer', 'between:1,'.config('fleetfuel.exchange_rates.max_age_hours')],
            'starts_at' => ['nullable', $this->businessTimeRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['rate' => 'rate (LBP per USD)', 'valid_for_hours' => 'validity', 'starts_at' => 'start time'];
    }

    public function rate(): string
    {
        return (string) $this->validated('rate');
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }

    public function validForHours(): int
    {
        return (int) $this->validated('valid_for_hours');
    }

    public function startsAt(): ?CarbonImmutable
    {
        return $this->businessTime('starts_at');
    }
}
