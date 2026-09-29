<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;

/**
 * The correlation ID of the current HTTP request. AssignRequestId sets it
 * for every request, returns it in the X-Request-Id header and adds it to
 * every log entry; API errors and audit rows store it too.
 */
final class RequestId
{
    public const CONTEXT_KEY = 'request_id';

    public const HEADER = 'X-Request-Id';

    /** Null outside an HTTP request, for example in an Artisan command. */
    public static function current(): ?string
    {
        $id = Context::get(self::CONTEXT_KEY);

        return is_string($id) ? $id : null;
    }
}
