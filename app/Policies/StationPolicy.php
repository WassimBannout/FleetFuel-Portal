<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;
use App\Policies\Concerns\DeniesInactiveUsers;

class StationPolicy
{
    use DeniesInactiveUsers;

    /** Every role reads the station list; Station::visibleTo() hides inactive ones from non-admins. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Station $station): bool
    {
        return $user->isAdmin() || $station->is_active || $user->operatesStation($station->id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Station $station): bool
    {
        return $user->isAdmin();
    }
}
