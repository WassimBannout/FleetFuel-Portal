<?php

namespace App\Http\Middleware;

use Closure;
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
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'Your account has been disabled. Contact an administrator.');
        }

        return $next($request);
    }
}
