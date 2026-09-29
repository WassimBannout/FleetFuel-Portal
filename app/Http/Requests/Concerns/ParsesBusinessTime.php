<?php

namespace App\Http\Requests\Concerns;

use Carbon\CarbonImmutable;

/**
 * Admins type times into `datetime-local` inputs in Beirut business time
 * ("2026-10-01T06:00"); the database stores UTC.
 */
trait ParsesBusinessTime
{
    private const BUSINESS_TIME_FORMAT = 'Y-m-d\TH:i';

    /** The rule for such an input; empty means "now". */
    protected function businessTimeRule(): string
    {
        return 'date_format:'.self::BUSINESS_TIME_FORMAT;
    }

    protected function businessTime(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        // "!" resets the fields the format lacks (seconds) to zero instead of
        // taking them from the current time.
        $local = CarbonImmutable::createFromFormat('!'.self::BUSINESS_TIME_FORMAT, $value, (string) config('fleetfuel.business_timezone'));

        return $local === null ? null : $local->utc();
    }
}
