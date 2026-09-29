<?php

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An ISO 8601 instant with an explicit offset and whole seconds, such as
 * "2026-09-28T10:00:00+03:00" or "2026-09-28T07:00:00Z". A value without a
 * zone would be ambiguous, fractional seconds are refused for the MVP, and
 * an impossible date such as 30 February is not silently rolled over.
 */
class OffsetTimestamp implements ValidationRule
{
    private const PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-](\d{2}):(\d{2}))$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::parse($value) === null) {
            $fail('The :attribute must be a date and time with seconds and a UTC offset, such as 2026-09-28T10:00:00+03:00.');
        }
    }

    /** The instant in UTC, or null if the value is not acceptable. */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }

        // Real-world offsets run from -12:00 to +14:00.
        if ($match[1] !== 'Z' && ((int) $match[2] > 14 || (int) $match[3] > 59)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);

        // A round trip catches rolled-over dates (2026-02-30 becoming
        // 2 March) and out-of-range hours, minutes or seconds.
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            return null;
        }

        return CarbonImmutable::instance($parsed)->utc();
    }
}
