<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Running totals for one card in one Beirut calendar month. Changed only
 * together with an accepted fuel transaction, under the card lock.
 */
#[Table('card_monthly_usage')]
class CardMonthlyUsage extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'used_l' => '0.00',
        'used_usd' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month_start' => 'date:Y-m-d',
            'used_l' => 'decimal:2',
            'used_usd' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<FuelCard, $this> */
    public function fuelCard(): BelongsTo
    {
        return $this->belongsTo(FuelCard::class);
    }
}
