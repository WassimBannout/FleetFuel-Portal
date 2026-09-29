<?php

namespace App\Policies;

use App\Models\Driver;
use App\Models\User;
use App\Policies\Concerns\DeniesInactiveUsers;

class DriverPolicy
{
    use DeniesInactiveUsers;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    public function view(User $user, Driver $driver): bool
    {
        return $user->isAdmin() || $user->managesCompany($driver->company_id);
    }

    /** A manager creates drivers for their own company only; the server sets company_id. */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    public function update(User $user, Driver $driver): bool
    {
        return $user->isAdmin() || $user->managesCompany($driver->company_id);
    }
}
