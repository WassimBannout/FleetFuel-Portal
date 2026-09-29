<?php

namespace App\Support;

/**
 * What one `rates:sync` run did, in words for the console and the log.
 */
final readonly class RateSyncResult
{
    public function __construct(
        // stored, already_stored, conflicting, skipped, busy or failed
        public string $status,
        public string $message,
    ) {}

    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
