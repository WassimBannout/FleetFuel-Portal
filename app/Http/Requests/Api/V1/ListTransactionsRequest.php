<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ProductCode;
use App\Http\Requests\Concerns\RejectsUnknownFields;
use App\Models\FuelTransaction;
use App\Models\User;
use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /api/v1/transactions filters (docs/05-API-CONTRACT.md). Dates are
 * Beirut calendar days: `from` inclusive, `to` exclusive, at most 366 days,
 * defaulting to the current Beirut month. Only an admin may filter by
 * company; for everyone else ownership comes from the account.
 */
class ListTransactionsRequest extends FormRequest
{
    use RejectsUnknownFields {
        after as rejectUnknownFields;
    }

    private const MAX_RANGE_DAYS = 366;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FuelTransaction::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after:from'],
            'card' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'station_id' => ['nullable', 'integer', 'min:1'],
            'product_code' => ['nullable', 'string', Rule::enum(ProductCode::class)],
            // Refused for managers even when equal to their own company.
            'company_id' => $user instanceof User && $user->isAdmin() ? ['nullable', 'integer', 'min:1'] : ['prohibited'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
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
                $from = $this->input('from');
                $to = $this->input('to');

                if ($validator->errors()->hasAny(['from', 'to']) || ! is_string($from) || ! is_string($to)) {
                    return;
                }

                if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add('to', 'The date range may cover at most '.self::MAX_RANGE_DAYS.' days.');
                }
            },
        ];
    }

    /**
     * The filter's half-open UTC range: [from, to). Each Beirut midnight is
     * converted on its own, so daylight-saving changes are respected.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function utcRange(): array
    {
        $timezone = (string) config('fleetfuel.business_timezone');
        $from = $this->validated('from');
        $to = $this->validated('to');

        if (is_string($from) && is_string($to)) {
            return [
                CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
                CarbonImmutable::parse($to, $timezone)->startOfDay()->utc(),
            ];
        }

        $month = BusinessMonth::for(CarbonImmutable::now());

        return [
            BusinessMonth::startUtc($month),
            BusinessMonth::startUtc(CarbonImmutable::parse($month)->addMonthNoOverflow()->format('Y-m-d')),
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 25);
    }
}
