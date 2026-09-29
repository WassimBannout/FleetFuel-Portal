<?php

namespace App\Models;

use App\Enums\RateSource;
use App\Enums\UserRole;
use App\Models\Concerns\AppendOnly;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accepted purchase with the ownership, price and exchange-rate values
 * that applied when it happened. Append-only: never updated, never deleted.
 */
class FuelTransaction extends Model
{
    use AppendOnly;

    /** created_at is the time the POS request was received. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tank_capacity_l' => 'decimal:2',
            'liters' => 'decimal:2',
            'odometer_km' => 'integer',
            'unit_price_lbp' => 'decimal:4',
            'amount_lbp' => 'decimal:2',
            'rate_lbp_per_usd' => 'decimal:8',
            'rate_source' => RateSource::class,
            'rate_effective_at' => 'datetime',
            'amount_usd' => 'decimal:2',
            'transacted_at' => 'datetime',
            'quota_month' => 'date:Y-m-d',
        ];
    }

    /**
     * Admin: all purchases. Company manager: own company's purchases.
     * Station operator: purchases made at their own station only.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match ($user->role) {
            UserRole::Admin => null,
            UserRole::CompanyManager => $query->where($query->qualifyColumn('company_id'), $user->company_id),
            UserRole::StationOperator => $query->where($query->qualifyColumn('station_id'), $user->station_id),
        };
    }

    /**
     * More liters than the vehicle's tank held at purchase time. Accepted,
     * but flagged as an anomaly (docs/04-BUSINESS-RULES.md).
     */
    public function exceedsTankCapacity(): bool
    {
        return $this->tank_capacity_l !== null
            && BigDecimal::of($this->liters)->isGreaterThan($this->tank_capacity_l);
    }

    /** @return BelongsTo<FuelCard, $this> */
    public function fuelCard(): BelongsTo
    {
        return $this->belongsTo(FuelCard::class);
    }

    /** @return BelongsTo<Station, $this> */
    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
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
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductPrice, $this> */
    public function productPrice(): BelongsTo
    {
        return $this->belongsTo(ProductPrice::class);
    }

    /** @return BelongsTo<ExchangeRate, $this> */
    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
