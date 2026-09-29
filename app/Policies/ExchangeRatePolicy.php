<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\DeniesInactiveUsers;

/**
 * FX integration status and manual overrides are admin-only. Other roles
 * only ever see converted amounts, never the rate administration.
 */
class ExchangeRatePolicy
{
    use DeniesInactiveUsers;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Enter a manual override (append-only; audited in M04). */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
