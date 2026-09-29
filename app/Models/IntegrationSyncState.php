<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Last attempt/success of an external sync such as rates:sync, one row per
 * exchange-rate mode. last_error_code is a safe short code (null after a
 * clean success); next_attempt_at holds the provider's next-update time or
 * its 429 retry advice.
 */
#[Fillable(['name', 'last_attempt_at', 'last_success_at', 'last_error_code', 'next_attempt_at'])]
class IntegrationSyncState extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    /** The last error code in words for the admin status page. */
    public function errorDescription(): ?string
    {
        return match ($this->last_error_code) {
            null => null,
            'connection_failed' => 'The provider could not be reached (connection error or timeout), even after retries.',
            'server_error' => 'The provider kept answering with a server error (HTTP 5xx), even after retries.',
            'rate_limited' => 'The provider rate-limited this server (HTTP 429); the next attempt waits for its retry advice.',
            'http_error' => 'The provider refused the request (unexpected HTTP status).',
            'invalid_response', 'provider_error' => 'The provider response was not a successful rate response.',
            'unexpected_base' => 'The provider response was not based on USD.',
            'invalid_rate' => 'The provider response had no usable positive LBP rate.',
            'invalid_timestamp' => 'The provider response had a missing or future observation time.',
            'stale_observation' => 'The provider observation was already older than 72 hours.',
            'conflicting_observation' => 'The provider sent a different value for an observation already stored; the stored value was kept.',
            default => "Sync error: {$this->last_error_code}.",
        };
    }
}
