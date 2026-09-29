<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role boundary, e.g. ->middleware('role:admin,company_manager').
 * It keeps whole areas away from the wrong role (403). It does not replace
 * policies: ownership of a specific record is still checked per request.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;

        $allowed = array_map(fn (string $value): UserRole => UserRole::from($value), $roles);

        if ($role === null || ! in_array($role, $allowed, true)) {
            abort(403);
        }

        return $next($request);
    }
}
