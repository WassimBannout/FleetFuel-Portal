<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An expected API error with a documented code (docs/05-API-CONTRACT.md,
 * "Status and error codes"). ApiErrorRenderer turns it into the standard
 * envelope; it is not reported to the error log.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
