<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Enums\RateMode;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\PriceUnavailable;
use App\Exceptions\PurchaseDeclined;
use App\Exceptions\RateUnavailable;
use App\Exceptions\TemporarilyUnavailable;
use App\Models\CardMonthlyUsage;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Support\BusinessMonth;
use App\Support\Decimal;
use App\Support\FuelAmounts;
use App\Support\IngestResult;
use App\Support\PosPurchase;
use App\Support\PriceQuote;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

/**
 * Records accepted fuel purchases: the POS API (M05) and demo history both
 * go through here, so the ledger and the monthly counters always follow the
 * same rules (docs/04-BUSINESS-RULES.md, "POS request, idempotency and
 * quota algorithm").
 *
 * Two different problems, two different tools:
 * - Lost updates: two purchases on one card must not both read "80 L used"
 *   and each add 15 L to a 100 L quota. The card row lock (SELECT … FOR
 *   UPDATE) makes every station spending on a card take turns.
 * - Duplicate retries: the same POS request sent twice must not spend
 *   twice. The unique (station_id, external_ref) index is the final judge,
 *   even for requests on different cards that no card lock serializes.
 */
class FuelTransactionService
{
    use DetectsConcurrencyErrors;

    /** Whole-transaction attempts for deadlocks, lock timeouts and unresolved same-reference races. */
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly PriceResolver $prices) {}

    /**
     * POS ingestion, steps 3–9. The caller already authenticated an active
     * operator of an active station and validated the request's structure
     * (steps 1–2). The station always comes from the operator's account.
     *
     * @throws IdempotencyConflict same station and reference, different purchase (409)
     * @throws PurchaseDeclined a business decline (403) or unknown card (404)
     * @throws ValidationException outside the 72-hour window, or an amount too large (422)
     * @throws PriceUnavailable no price at the event time (422)
     * @throws RateUnavailable no valid exchange rate at the event time (503)
     * @throws TemporarilyUnavailable contention retries ran out (503)
     */
    public function ingest(User $operator, PosPurchase $purchase): IngestResult
    {
        $stationId = (int) $operator->station_id;
        $hash = $purchase->requestHash($stationId);

        // Step 3: a retry of an accepted request is answered from the stored
        // row, before any rule that may have changed since: card status,
        // price, quota or the event's age.
        $existing = $this->findByReference($stationId, $purchase->externalRef);
        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn (): IngestResult => $this->ingestUnderCardLock($operator, $purchase, $hash));
            } catch (UniqueConstraintViolationException) {
                // Step 9: a concurrent request with the same station and
                // reference inserted first (possibly for another card). Our
                // transaction is rolled back; read the winner afresh.
                $winner = $this->findByReference($stationId, $purchase->externalRef);

                if ($winner !== null) {
                    return $this->replay($winner, $hash);
                }
            } catch (QueryException $e) {
                if (! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }
            }

            if ($attempt >= self::MAX_ATTEMPTS) {
                throw TemporarilyUnavailable::contention();
            }

            // A short, slightly random pause lets the competing transaction finish.
            Sleep::for(random_int(20, 60) * $attempt)->milliseconds();
        }
    }

    /**
     * Record a past purchase for demo or test history. The same rules and
     * locks apply, except the request-time ones (72-hour window, replay),
     * and demo history is always priced in fixture mode.
     *
     * @throws PurchaseDeclined
     */
    public function recordHistorical(
        FuelCard $card,
        Station $station,
        User $operator,
        Product $product,
        string $liters,
        CarbonImmutable $transactedAt,
        string $externalRef,
        ?int $odometerKm,
        CarbonImmutable $receivedAt,
    ): FuelTransaction {
        if (! $station->is_active) {
            throw PurchaseDeclined::stationInactive();
        }

        $purchase = PosPurchase::normalized($externalRef, $card->card_no, $product->code, $liters, $transactedAt, $odometerKm);

        return DB::transaction(function () use ($card, $station, $operator, $product, $purchase, $receivedAt): FuelTransaction {
            $card = FuelCard::query()->lockForUpdate()->findOrFail($card->id);

            return $this->record($card, $station, $operator, $product, $purchase, $purchase->requestHash($station->id), $receivedAt, RateMode::Fixture);
        });
    }

    private function ingestUnderCardLock(User $operator, PosPurchase $purchase, string $hash): IngestResult
    {
        // Step 4: the card lock comes first, so every station spending on
        // this card queues here, in order.
        $card = FuelCard::query()->where('card_no', $purchase->cardNo)->lockForUpdate()->first()
            ?? throw PurchaseDeclined::cardNotFound();

        // Recheck under the lock. This is the transaction's first plain
        // read, so under REPEATABLE READ its snapshot starts now and sees
        // an identical request that committed while we waited for the card.
        $existing = $this->findByReference((int) $operator->station_id, $purchase->externalRef);
        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        // Step 5: the window applies to new events only; replays skip it.
        $this->ensureWithinWindow($purchase->transactedAt);

        $station = Station::query()->findOrFail($operator->station_id);
        $product = Product::query()->where('code', $purchase->productCode)->firstOrFail();

        return new IngestResult(
            $this->record($card, $station, $operator, $product, $purchase, $hash, CarbonImmutable::now()),
            replayed: false,
        );
    }

    /**
     * Steps 5–8 for a card this transaction has locked: check, price,
     * lock the month's counter, check the quota, insert, increment.
     */
    private function record(
        FuelCard $card,
        Station $station,
        User $operator,
        Product $product,
        PosPurchase $purchase,
        string $hash,
        CarbonImmutable $receivedAt,
        ?RateMode $mode = null,
    ): FuelTransaction {
        $card->loadMissing(['company', 'vehicle', 'driver']);
        $this->ensureCardAccepts($card, $product);

        // Step 6: the price and rate in effect at the event time, from
        // stored rows only (no HTTP inside the transaction).
        $quote = $this->prices->quote($product, $purchase->liters, $purchase->transactedAt, $mode);
        $quotaMonth = BusinessMonth::for($purchase->transactedAt);

        // Lock order: card (held), then this month's counter, then the ledger insert.
        $usage = $this->lockedUsage($card, $quotaMonth);

        // Step 7: current limits apply, even to a late event from last month.
        $this->ensureWithinQuota($card, $usage, $quote);

        $usedL = FuelAmounts::add($usage->used_l, $quote->liters);
        $usedUsd = FuelAmounts::add($usage->used_usd, $quote->amountUsd);

        if (! Decimal::fits($usedL, 14, 2) || ! Decimal::fits($usedUsd, 20, 2)) {
            throw ValidationException::withMessages(['liters' => 'This purchase would overflow the monthly usage counter.']);
        }

        // Step 8: one immutable ledger row with its snapshots, one increment.
        $transaction = FuelTransaction::query()->forceCreate([
            'fuel_card_id' => $card->id,
            'station_id' => $station->id,
            'external_ref' => $purchase->externalRef,
            'request_hash' => $hash,
            'company_id' => $card->company_id,
            'vehicle_id' => $card->vehicle_id,
            'driver_id' => $card->driver_id,
            'tank_capacity_l' => $card->vehicle?->tank_capacity_l,
            'product_id' => $product->id,
            'liters' => $quote->liters,
            'odometer_km' => $purchase->odometerKm,
            'product_price_id' => $quote->price->id,
            'unit_price_lbp' => $quote->price->price_lbp,
            'amount_lbp' => $quote->amountLbp,
            'exchange_rate_id' => $quote->rate->id,
            'rate_lbp_per_usd' => $quote->rate->rate,
            'rate_source' => $quote->rate->source,
            'rate_effective_at' => $quote->rate->effective_at,
            'amount_usd' => $quote->amountUsd,
            'transacted_at' => $purchase->transactedAt,
            'quota_month' => $quotaMonth,
            'created_by' => $operator->id,
            'created_at' => $receivedAt,
        ]);

        $usage->used_l = $usedL;
        $usage->used_usd = $usedUsd;
        $usage->save();

        return $transaction;
    }

    private function findByReference(int $stationId, string $externalRef): ?FuelTransaction
    {
        return FuelTransaction::query()
            ->where('station_id', $stationId)
            ->where('external_ref', $externalRef)
            ->first();
    }

    /** Equal canonical hash: the original row (200). Different: 409. */
    private function replay(FuelTransaction $existing, string $hash): IngestResult
    {
        if (! hash_equals($existing->request_hash, $hash)) {
            throw IdempotencyConflict::make();
        }

        return new IngestResult($existing, replayed: true);
    }

    private function ensureWithinWindow(CarbonImmutable $transactedAt): void
    {
        $now = CarbonImmutable::now();
        $maxAgeHours = (int) config('fleetfuel.pos.max_event_age_hours');

        if ($transactedAt->isAfter($now)) {
            throw ValidationException::withMessages(['transacted_at' => 'The purchase time is in the future.']);
        }

        if ($transactedAt->lessThan($now->subHours($maxAgeHours))) {
            throw ValidationException::withMessages(['transacted_at' => "The purchase time is more than {$maxAgeHours} hours ago."]);
        }
    }

    private function ensureCardAccepts(FuelCard $card, Product $product): void
    {
        match ($card->status) {
            CardStatus::Blocked => throw PurchaseDeclined::cardBlocked(),
            CardStatus::Archived => throw PurchaseDeclined::cardInactive(),
            CardStatus::Active => null,
        };

        if ($card->company->status !== CompanyStatus::Active) {
            throw PurchaseDeclined::companyInactive();
        }

        if ($card->vehicle !== null && ! $card->vehicle->is_active) {
            throw PurchaseDeclined::assignmentInactive('vehicle');
        }

        if ($card->driver !== null && ! $card->driver->is_active) {
            throw PurchaseDeclined::assignmentInactive('driver');
        }

        if (! $product->is_active) {
            throw PurchaseDeclined::productNotAllowed('product_inactive');
        }

        if ($card->allowed_product_id !== null && $card->allowed_product_id !== $product->id) {
            throw PurchaseDeclined::productNotAllowed('card_restriction');
        }

        // The vehicle's fuel type restricts products even without a card restriction.
        if ($card->vehicle !== null && $card->vehicle->fuel_type !== $product->fuel_type) {
            throw PurchaseDeclined::productNotAllowed('vehicle_fuel_type');
        }
    }

    /**
     * The card's counter for the month, created on its first purchase and
     * then locked. Only the holder of the card lock can create it, so a
     * plain existence check is safe, and no gap lock is taken that could
     * deadlock with another card's first purchase of the month.
     */
    private function lockedUsage(FuelCard $card, string $month): CardMonthlyUsage
    {
        $counter = fn () => CardMonthlyUsage::query()
            ->where('fuel_card_id', $card->id)
            ->where('month_start', $month);

        if (! $counter()->exists()) {
            CardMonthlyUsage::query()->forceCreate(['fuel_card_id' => $card->id, 'month_start' => $month]);
        }

        return $counter()->lockForUpdate()->firstOrFail();
    }

    /** used + requested <= limit for each limit that is set; null means unlimited. */
    private function ensureWithinQuota(FuelCard $card, CardMonthlyUsage $usage, PriceQuote $quote): void
    {
        if ($card->monthly_limit_l !== null && BigDecimal::of($usage->used_l)->plus($quote->liters)->isGreaterThan($card->monthly_limit_l)) {
            throw PurchaseDeclined::quotaExceeded('liters');
        }

        if ($card->monthly_limit_usd !== null && BigDecimal::of($usage->used_usd)->plus($quote->amountUsd)->isGreaterThan($card->monthly_limit_usd)) {
            throw PurchaseDeclined::quotaExceeded('usd');
        }
    }
}
