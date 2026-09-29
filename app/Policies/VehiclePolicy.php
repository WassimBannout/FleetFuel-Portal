<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Policies\Concerns\DeniesInactiveUsers;

class VehiclePolicy
{
    use DeniesInactiveUsers;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return $user->isAdmin() || $user->managesCompany($vehicle->company_id);
    }

    /** A manager creates vehicles for their own company only; the server sets company_id. */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->isAdmin() || $user->managesCompany($vehicle->company_id);
    }
}
