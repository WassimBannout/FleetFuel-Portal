<?php

namespace App\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A provider request that produced no usable observation. It is an
 * operational event, never a rate row: the stored rates stay untouched.
 *
 * $reason is a short safe code for integration_sync_states and logs; the
 * raw provider response is never stored.
 */
class ExchangeRateFetchFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $attempts = 1,
        // Earliest time to ask again (a 429's retry advice).
        public readonly ?CarbonImmutable $retryAt = null,
    ) {
        parent::__construct($message);
    }

    public static function connectionFailed(int $attempts): self
    {
        return new self('connection_failed', "No response from the provider after {$attempts} attempt(s) (connection error or timeout).", $attempts);
    }

    public static function serverError(int $status, int $attempts): self
    {
        return new self('server_error', "The provider answered HTTP {$status} after {$attempts} attempt(s).", $attempts);
    }

    public static function rateLimited(CarbonImmutable $retryAt, int $attempts): self
    {
        return new self('rate_limited', 'The provider rate-limited this server (HTTP 429).', $attempts, $retryAt);
    }

    public static function httpError(int $status, int $attempts): self
    {
        return new self('http_error', "The provider answered HTTP {$status}.", $attempts);
    }

    public static function invalidResponse(string $reason, string $message, int $attempts): self
    {
        return new self($reason, $message, $attempts);
    }
}
