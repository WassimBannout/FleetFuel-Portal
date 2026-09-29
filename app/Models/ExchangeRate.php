<?php

namespace App\Models;

use App\Enums\RateSource;
use App\Models\Concerns\AppendOnly;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable USD/LBP observation: provider data, a fixture or a manual
 * override. Eligible at time T when effective_at <= T < expires_at.
 */
class ExchangeRate extends Model
{
    use AppendOnly;

    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'source' => RateSource::class,
            'effective_at' => 'datetime',
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
