<?php

namespace App\Models\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant scope for records owned by one company (vehicles, drivers, cards,
 * delivery orders). It is explicit, never a global scope: every tenant query
 * calls ->visibleTo($user) before looking anything up (D12), so a guessed ID
 * from another company is simply not found (404).
 */
trait BelongsToCompany
{
    /**
     * Admin: every company. Company manager: own company only.
     * Station operator: none (these are not station records).
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            UserRole::Admin => null,
            UserRole::CompanyManager => $query->where($query->qualifyColumn('company_id'), $user->company_id),
            UserRole::StationOperator => $query->whereRaw('0 = 1'),
        };
    }
}
