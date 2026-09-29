<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait DeniesInactiveUsers
{
    /**
     * A disabled account may do nothing, whatever its role. Returning null
     * lets the specific policy method decide for active users.
     */
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }
}
