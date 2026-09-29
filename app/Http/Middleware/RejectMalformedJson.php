<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel silently treats an unparseable JSON body as empty input, which
 * would turn a client bug into misleading "field is required" errors. The
 * API answers 400 malformed_json instead (docs/05-API-CONTRACT.md).
 */
class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();

        if ($request->isJson() && trim($body) !== '') {
            try {
                $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }

            // The body must be a JSON object, not a bare string or number.
            if (! is_array($decoded)) {
                throw new ApiException(400, 'malformed_json', 'The request body is not a valid JSON object.');
            }
        }

        return $next($request);
    }
}
