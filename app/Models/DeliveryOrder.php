<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DeliveryOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A diesel delivery request. Status changes go through the delivery service
 * (M07), which also appends history and audit rows in one transaction.
 */
#[Fillable(['address', 'governorate', 'liters', 'preferred_start_at', 'preferred_end_at'])]
class DeliveryOrder extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<DeliveryOrderFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'liters' => 'decimal:2',
            'preferred_start_at' => 'datetime',
            'preferred_end_at' => 'datetime',
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'status' => DeliveryStatus::class,
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DeliveryStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(DeliveryStatusHistory::class)->orderBy('changed_at')->orderBy('id');
    }
}
