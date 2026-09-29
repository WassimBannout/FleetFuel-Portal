<?php

namespace App\Models;

use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'district', 'governorate', 'latitude', 'longitude'])]
class Station extends Model
{
    /** @use HasFactory<StationFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Stations are reference data: admins see all of them, everyone else
     * sees active stations only.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->where($query->qualifyColumn('is_active'), true);
        }
    }

    /** @return HasMany<User, $this> */
    public function operators(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<FuelTransaction, $this> */
    public function fuelTransactions(): HasMany
    {
        return $this->hasMany(FuelTransaction::class);
    }
}
