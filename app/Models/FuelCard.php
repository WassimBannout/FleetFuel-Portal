<?php

namespace App\Models;

use App\Enums\CardStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\FuelCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Only the monthly quotas are mass-assignable. Ownership, assignment,
 * product restriction and status change through dedicated service methods
 * that take the card lock and write an audit record (M03).
 */
#[Fillable(['monthly_limit_l', 'monthly_limit_usd'])]
class FuelCard extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<FuelCardFactory> */
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
            'monthly_limit_l' => 'decimal:2',
            'monthly_limit_usd' => 'decimal:2',
            'status' => CardStatus::class,
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<Driver, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function allowedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'allowed_product_id');
    }

    /** @return HasMany<CardMonthlyUsage, $this> */
    public function monthlyUsages(): HasMany
    {
        return $this->hasMany(CardMonthlyUsage::class);
    }

    /** @return HasMany<FuelTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(FuelTransaction::class);
    }
}
