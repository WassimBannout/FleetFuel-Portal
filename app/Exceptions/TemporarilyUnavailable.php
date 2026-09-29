<?php

namespace App\Exceptions;

/**
 * Bounded retries after database contention (deadlock, lock timeout or an
 * unresolved same-reference race) ran out. Nothing was recorded; the POS
 * may safely retry the identical request (503).
 */
class TemporarilyUnavailable extends ApiException
{
    public static function contention(): self
    {
        return new self(503, 'temporarily_unavailable', 'The purchase could not be recorded because of concurrent activity. Retry the same request.', [], ['Retry-After' => '1']);
    }
}
