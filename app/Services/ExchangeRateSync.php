<?php

namespace App\Services;

use App\Contracts\ExchangeRateProvider;
use App\Enums\ObservationOutcome;
use App\Enums\RateMode;
use App\Exceptions\ExchangeRateFetchFailed;
use App\Models\IntegrationSyncState;
use App\Support\RateSyncResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One `rates:sync` run: fetch the latest observation (outside any database
 * transaction), store it once, and record the outcome in
 * integration_sync_states for the admin status page.
 *
 * - Runs never overlap: a second run while one holds the lock does nothing.
 * - The provider's next-update time is respected, so repeated runs on the
 *   same day do not download again (unless forced).
 * - A failure leaves every stored rate as it was. Existing eligible rates
 *   keep working until they expire; nothing switches to fixtures.
 */
class ExchangeRateSync
{
    private const LOCK = 'rates-sync';

    public function __construct(
        private readonly ExchangeRateProvider $provider,
        private readonly ExchangeRateService $rates,
    ) {}

    public function run(bool $force = false): RateSyncResult
    {
        $lock = Cache::lock(self::LOCK, 120);

        if (! $lock->get()) {
            return new RateSyncResult('busy', 'Another rates:sync run is in progress; nothing was done.');
        }

        try {
            return $this->sync(RateMode::current(), $force);
        } finally {
            $lock->release();
        }
    }

    private function sync(RateMode $mode, bool $force): RateSyncResult
    {
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $state = IntegrationSyncState::query()->firstOrCreate(['name' => $mode->syncStateName()]);

        if (! $force && $state->next_attempt_at?->isAfter($now)) {
            return new RateSyncResult('skipped', "Nothing fetched: the next {$mode->value} update is not due until {$state->next_attempt_at->toIso8601ZuluString()}. Use --force to fetch anyway.");
        }

        $state->last_attempt_at = $now;

        try {
            $observation = $this->provider->latest();
        } catch (ExchangeRateFetchFailed $e) {
            $state->last_error_code = $e->reason;
            $state->next_attempt_at = $e->retryAt;
            $state->save();

            Log::warning('Exchange-rate sync failed; stored rates are unchanged.', [
                'mode' => $mode->value,
                'reason' => $e->reason,
                'attempts' => $e->attempts,
                'retry_at' => $e->retryAt?->toIso8601ZuluString(),
            ]);

            return new RateSyncResult('failed', "{$e->getMessage()} Stored rates are unchanged.");
        }

        $outcome = $this->rates->recordObservation($observation);

        $state->last_success_at = $now;
        $state->last_error_code = $outcome === ObservationOutcome::Conflicting ? 'conflicting_observation' : null;
        $state->next_attempt_at = $observation->nextUpdateAt;
        $state->save();

        $label = "{$observation->source->label()} observation of {$observation->rate} LBP per USD at {$observation->effectiveAt->toIso8601ZuluString()}";

        return match ($outcome) {
            ObservationOutcome::Stored => new RateSyncResult('stored', "Stored the {$label}."),
            ObservationOutcome::AlreadyStored => new RateSyncResult('already_stored', "The {$label} was already stored; nothing changed."),
            ObservationOutcome::Conflicting => new RateSyncResult('conflicting', "Kept the stored value: a different rate is already stored for {$observation->effectiveAt->toIso8601ZuluString()} (see the log)."),
        };
    }
}
