<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;

/**
 * The one credential check behind both the web login (Fortify) and API
 * token issuance, so the two cannot drift apart.
 */
class CredentialVerifier
{
    public function __construct(private readonly Hasher $hasher) {}

    /**
     * The active user with this email and password, or null. An unknown
     * email, a wrong password and a disabled account all return null, so a
     * caller cannot tell which accounts exist or which are disabled.
     */
    public function verify(string $email, string $password): ?User
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if ($user === null) {
            // Spend the same hashing time as a real check, so response times
            // do not reveal whether the email is registered.
            $this->hasher->make($password);

            return null;
        }

        if (! $this->hasher->check($password, $user->password) || ! $user->is_active) {
            return null;
        }

        return $user;
    }
}
