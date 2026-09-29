<?php

namespace App\Services;

use App\Enums\ObservationOutcome;
use App\Enums\RateSource;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\RateObservation;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Writes USD/LBP rows. They are immutable (D10): an observation is stored
 * once, and an admin override is a new row that expires on its own.
 */
class ExchangeRateService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Store a validated provider or fixture observation once. Fetching the
     * same observation again changes nothing. If the provider sends a
     * different value for an instant already stored, the stored value is
     * kept and the discrepancy is logged.
     */
    public function recordObservation(RateObservation $observation): ObservationOutcome
    {
        $existing = $this->storedObservation($observation);

        if ($existing === null) {
            try {
                ExchangeRate::query()->forceCreate([
                    'base' => 'USD',
                    'quote' => 'LBP',
                    'rate' => $observation->rate,
                    'source' => $observation->source,
                    'effective_at' => $observation->effectiveAt,
                    'fetched_at' => $observation->fetchedAt,
                    'expires_at' => $observation->effectiveAt->addHours($this->maxAgeHours()),
                    'created_by' => null,
                    'reason' => null,
                ]);

                return ObservationOutcome::Stored;
            } catch (UniqueConstraintViolationException) {
                // A concurrent sync stored the same observation first.
                $existing = $this->storedObservation($observation)
                    ?? throw new LogicException('An exchange-rate observation clashed with a row that cannot be found.');
            }
        }

        if (BigDecimal::of($existing->rate)->isEqualTo($observation->rate)) {
            return ObservationOutcome::AlreadyStored;
        }

        Log::warning('Exchange-rate provider sent a different value for a stored observation; the stored value is kept.', [
            'exchange_rate_id' => $existing->id,
            'source' => $observation->source->value,
            'effective_at' => $observation->effectiveAt->toIso8601ZuluString(),
            'stored_rate' => $existing->rate,
            'received_rate' => $observation->rate,
        ]);

        return ObservationOutcome::Conflicting;
    }

    /**
     * An admin's manual USD/LBP rate. It starts now or later (never in the
     * past), lasts at most 72 hours, needs a reason, and is audited. It
     * cannot be edited or ended early; a newer override takes precedence.
     */
    public function createOverride(User $actor, string $rate, string $reason, int $validForHours, ?CarbonImmutable $startsAt = null): ExchangeRate
    {
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $effectiveAt = $startsAt?->utc()->startOfSecond() ?? $now;

        if ($effectiveAt->lessThan($now)) {
            throw ValidationException::withMessages(['starts_at' => 'An override cannot start in the past.']);
        }

        if ($validForHours < 1 || $validForHours > $this->maxAgeHours()) {
            throw ValidationException::withMessages(['valid_for_hours' => "An override lasts between 1 and {$this->maxAgeHours()} hours."]);
        }

        try {
            return DB::transaction(function () use ($actor, $rate, $reason, $effectiveAt, $validForHours): ExchangeRate {
                $override = ExchangeRate::query()->forceCreate([
                    'base' => 'USD',
                    'quote' => 'LBP',
                    'rate' => $rate,
                    'source' => RateSource::Manual,
                    'effective_at' => $effectiveAt,
                    'fetched_at' => null,
                    'expires_at' => $effectiveAt->addHours($validForHours),
                    'created_by' => $actor->id,
                    'reason' => $reason,
                ]);

                $this->audit->record('exchange_rate.override_created', $override, $actor, null, null, [
                    'rate' => $override->rate,
                    'effective_at' => $override->effective_at->toIso8601ZuluString(),
                    'expires_at' => $override->expires_at->toIso8601ZuluString(),
                    'reason' => $reason,
                ]);

                return $override;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['starts_at' => 'Another override already starts at that second.']);
        }
    }

    private function storedObservation(RateObservation $observation): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->usdLbp()
            ->where('source', $observation->source)
            ->where('effective_at', $observation->effectiveAt->utc())
            ->first();
    }

    private function maxAgeHours(): int
    {
        return (int) config('fleetfuel.exchange_rates.max_age_hours');
    }
}
