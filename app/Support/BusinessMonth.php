<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Quota months follow the Beirut calendar, not the UTC or server month.
 * Timestamps are stored in UTC and converted here, so daylight-saving
 * changes are handled by the timezone database instead of a fixed offset.
 */
final class BusinessMonth
{
    /**
     * The quota month ("Y-m-d" of the first local day) containing an instant.
     */
    public static function for(CarbonInterface $instant, ?string $timezone = null): string
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone(self::timezone($timezone))
            ->startOfMonth()
            ->format('Y-m-d');
    }

    /**
     * The UTC instant at which a business month begins (local midnight, day 1).
     */
    public static function startUtc(string $month, ?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::parse($month, self::timezone($timezone))
            ->startOfMonth()
            ->utc();
    }

    private static function timezone(?string $timezone): string
    {
        return $timezone ?? (string) config('fleetfuel.business_timezone');
    }
}
