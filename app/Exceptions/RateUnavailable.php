<?php

namespace App\Exceptions;

/**
 * No eligible USD/LBP rate at the event time (none effective, or all
 * expired). The request fails with 503 and nothing changes; a missing rate
 * is never treated as zero or 1:1 (D09).
 */
class RateUnavailable extends ApiException
{
    public static function make(): self
    {
        return new self(503, 'rate_unavailable', 'No valid USD/LBP exchange rate is available for that time.');
    }
}
