<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * company_id is set by the server when the vehicle is created and never
 * changes afterwards, so it is deliberately not mass-assignable.
 */
#[Fillable(['plate_no', 'fuel_type', 'tank_capacity_l', 'odometer_km'])]
class Vehicle extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    /** " ab  123 " becomes "AB 123": plates are stored uppercase with single spaces. */
    public static function normalizePlate(string $plate): string
    {
        return Str::upper((string) preg_replace('/\s+/', ' ', trim($plate)));
    }

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
            'fuel_type' => FuelType::class,
            'tank_capacity_l' => 'decimal:2',
            'odometer_km' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
}
