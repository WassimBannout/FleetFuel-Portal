<?php

namespace App\Policies;

use App\Enums\DeliveryStatus;
use App\Models\DeliveryOrder;
use App\Models\User;
use App\Policies\Concerns\DeniesInactiveUsers;

class DeliveryOrderPolicy
{
    use DeniesInactiveUsers;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    public function view(User $user, DeliveryOrder $order): bool
    {
        return $user->isAdmin() || $user->managesCompany($order->company_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isCompanyManager();
    }

    /** Schedule, dispatch and deliver: distributor staff only. */
    public function advance(User $user, DeliveryOrder $order): bool
    {
        return $user->isAdmin();
    }

    /** An admin may cancel any open order; a manager only their own pending one. */
    public function cancel(User $user, DeliveryOrder $order): bool
    {
        if ($user->isAdmin()) {
            return ! $order->status->isTerminal();
        }

        return $user->managesCompany($order->company_id)
            && $order->status === DeliveryStatus::Pending;
    }
}
