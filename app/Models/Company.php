<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'tax_no'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    /**
     * Admin: every company. Company manager: own company only.
     * Station operator: none.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            UserRole::Admin => null,
            UserRole::CompanyManager => $query->whereKey($user->company_id),
            UserRole::StationOperator => $query->whereRaw('0 = 1'),
        };
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Vehicle, $this> */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** @return HasMany<Driver, $this> */
    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    /** @return HasMany<FuelCard, $this> */
    public function fuelCards(): HasMany
    {
        return $this->hasMany(FuelCard::class);
    }

    /** @return HasMany<FuelTransaction, $this> */
    public function fuelTransactions(): HasMany
    {
        return $this->hasMany(FuelTransaction::class);
    }

    /** @return HasMany<DeliveryOrder, $this> */
    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }
}
