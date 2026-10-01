<?php

namespace App\Http\Requests\Concerns;

use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * `from`/`to` filters as Beirut calendar days: `from` inclusive, `to`
 * exclusive, both or neither, at most 366 days apart, defaulting to the
 * current Beirut month. Used by the ledger list, the CSV export and the
 * reports.
 */
trait FiltersBusinessDates
{
    /**
     * @return array<string, list<string>>
     */
    protected function dateRangeRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after:from'],
        ];
    }

    /**
     * The 366-day limit, for the request's after() hooks.
     *
     * @return callable(Validator): void
     */
    protected function dateRangeLimit(): callable
    {
        return function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if ($validator->errors()->hasAny(['from', 'to']) || ! is_string($from) || ! is_string($to)) {
                return;
            }

            if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
                $validator->errors()->add('to', 'The date range may cover at most 366 days.');
            }
        };
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

    /**
     * The Beirut dates shown back to the user: [first day, last day], both
     * inclusive, for headings and file names.
     *
     * @return array{string, string}
     */
    public function businessDates(): array
    {
        [$from, $to] = $this->utcRange();
        $timezone = (string) config('fleetfuel.business_timezone');

        return [$from->setTimezone($timezone)->format('Y-m-d'), $to->setTimezone($timezone)->subDay()->format('Y-m-d')];
    }
}
