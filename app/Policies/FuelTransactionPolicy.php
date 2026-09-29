<?php

namespace App\Policies;

use App\Models\FuelTransaction;
use App\Models\User;
use App\Policies\Concerns\DeniesInactiveUsers;

/**
 * Accepted purchases are immutable, so there is no update or delete ability
 * for anyone (a missing policy method always denies).
 */
class FuelTransactionPolicy
{
    use DeniesInactiveUsers;

    /** Every role has a transaction list; FuelTransaction::visibleTo() scopes it. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FuelTransaction $transaction): bool
    {
        return $user->isAdmin()
            || $user->managesCompany($transaction->company_id)
            || $user->operatesStation($transaction->station_id);
    }

    /**
     * Only a station operator submits POS purchases, always for their own
     * station (M05 also requires the transactions:create token ability and
     * an active station).
     */
    public function create(User $user): bool
    {
        return $user->isStationOperator();
    }
}
