<?php

namespace App\Exceptions;

/**
 * The station already used this external_ref for a different purchase
 * (a different canonical payload). The original stays as it was (409).
 */
class IdempotencyConflict extends ApiException
{
    public static function make(): self
    {
        return new self(409, 'idempotency_conflict', 'This external_ref was already used for a different purchase at this station.');
    }
}
