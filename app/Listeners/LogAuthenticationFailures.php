<?php

namespace App\Listeners;

use App\Support\Redact;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Log;

/**
 * Security log for failed sign-ins and lockouts (web login and API token
 * requests). The email is masked and the password is never written; the
 * request ID is added to every entry automatically.
 */
class LogAuthenticationFailures
{
    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        Log::warning('Sign-in failed.', [
            'guard' => $event->guard,
            'email' => Redact::email(is_string($email) ? $email : null),
            'ip' => request()->ip(),
        ]);
    }

    public function handleLockout(Lockout $event): void
    {
        Log::warning('Sign-in locked out after repeated failures.', [
            'email' => Redact::email($event->request->string('email')->toString()),
            'ip' => $event->request->ip(),
        ]);
    }
}
