<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the browser session of a user who was deactivated after signing in.
 * The user is re-read from the database on every request, so the change
 * applies on their next click. (API tokens of disabled users are rejected
 * by the Sanctum check in AppServiceProvider.)
 */
class EnsureUserIsActive
{
    private const DISABLED = 'Your account has been disabled. Contact an administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // A page script gets 401, like a signed-out session, instead of
            // the sign-in page as a 200 it cannot read.
            if ($request->expectsJson()) {
                throw new AuthenticationException(self::DISABLED);
            }

            return redirect()->route('login')->with('status', self::DISABLED);
        }

        return $next($request);
    }
}
