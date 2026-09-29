<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesInactiveUsers;

/**
 * Prices are append-only: there is no update or delete ability. Every role
 * can read them; only an admin publishes a new one.
 */
class ProductPricePolicy
{
    use DeniesInactiveUsers;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
