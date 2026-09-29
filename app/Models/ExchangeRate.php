<?php

namespace App\Models;

use App\Enums\RateSource;
use App\Models\Concerns\AppendOnly;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The only pair the portal converts: LBP per USD.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUsdLbp(Builder $query): void
    {
        $query->where('base', 'USD')->where('quote', 'LBP');
    }

    /**
     * Rows that may convert an event at $at: effective_at <= T < expires_at.
     *
     * @param  Builder<static>  $query
     */
    public function scopeEligibleAt(Builder $query, CarbonInterface $at): void
    {
        // Bound as UTC text: DATETIME columns hold UTC (DECISIONS, M01).
        $at = CarbonImmutable::instance($at)->utc();

        $query->where('effective_at', '<=', $at)->where('expires_at', '>', $at);
    }

    public function isEligibleAt(CarbonInterface $at): bool
    {
        return $this->effective_at->lessThanOrEqualTo($at) && $this->expires_at->greaterThan($at);
    }
}
