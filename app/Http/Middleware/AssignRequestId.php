<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a server-generated ID. A client-supplied X-Request-Id
 * is ignored, so nobody can inject text into logs or collide with another
 * request's ID.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::uuid();

        // Context values are attached to every log entry written from here on.
        Context::add(RequestId::CONTEXT_KEY, $id);

        $response = $next($request);
        $response->headers->set(RequestId::HEADER, $id);

        return $response;
    }
}
