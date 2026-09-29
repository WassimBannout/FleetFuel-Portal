<?php

namespace App\Policies;

use App\Models\FuelCard;
use App\Models\User;
use App\Policies\Concerns\DeniesInactiveUsers;

class FuelCardPolicy
{
    use DeniesInactiveUsers;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    public function view(User $user, FuelCard $card): bool
    {
        return $user->isAdmin() || $user->managesCompany($card->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    /**
     * Quota and block/unblock changes. Station operators never change
     * cards. (M03 adds the card lock and audit around these writes.)
     */
    public function update(User $user, FuelCard $card): bool
    {
        return $user->isAdmin() || $user->managesCompany($card->company_id);
    }
}
