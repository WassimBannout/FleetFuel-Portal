<?php

namespace Database\Seeders\Support;

use App\Enums\DeliveryStatus;
use App\Enums\RateSource;
use App\Enums\UserRole;
use App\Exceptions\BusinessRuleViolation;
use App\Exceptions\PurchaseDeclined;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Services\DeliveryOrderService;
use App\Services\FuelCardService;
use App\Services\FuelTransactionService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Writes historical demo/test data with the same rules live writes follow:
 *
 * - A purchase goes through FuelTransactionService::recordHistorical(), the
 *   code POS ingestion uses: card lock, then the monthly usage row, one
 *   ledger row with snapshots and exactly one counter increment. Anything
 *   the live service would decline is refused here too, so fixtures never
 *   contain impossible history. Only request-time rules (the 72-hour
 *   window, replays) are skipped, because the purpose is to record the past.
 * - Delivery orders go through DeliveryOrderService's historical methods,
 *   the code the web screens and the API use, judged at the given time.
 * - Quota changes go through FuelCardService::updateLimitsHistorical(), so
 *   their audit rows are the ones a live change writes.
 * - Manual rate overrides write their audit row in the same transaction as
 *   the override (the live service refuses a start in the past).
 */
final class LedgerFixtureBuilder
{
    public function __construct(
        private readonly FuelTransactionService $transactions,
        private readonly DeliveryOrderService $deliveries,
        private readonly FuelCardService $cards,
    ) {}

    public function recordPurchase(
        FuelCard $card,
        Station $station,
        User $operator,
        Product $product,
        string $liters,
        CarbonImmutable $transactedAt,
        string $externalRef,
        ?int $odometerKm = null,
    ): FuelTransaction {
        if ($operator->role !== UserRole::StationOperator || $operator->station_id !== $station->id) {
            throw new LogicException("Fixture purchase on {$card->card_no} refused: the recording user is not an operator of that station.");
        }

        try {
            return $this->transactions->recordHistorical(
                $card, $station, $operator, $product, $liters, $transactedAt->utc(), $externalRef, $odometerKm,
                // Received a minute after the pump event.
                $transactedAt->utc()->addMinute(),
            );
        } catch (PurchaseDeclined $e) {
            throw new LogicException("Fixture purchase on {$card->card_no} refused ({$e->errorCode}): {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Change a card's monthly limits at $at with the rules and audit row of a
     * live change (FuelCardService). A limit missing from $limits is kept.
     *
     * @param  array{monthly_limit_l?: ?string, monthly_limit_usd?: ?string}  $limits
     */
    public function changeMonthlyLimits(FuelCard $card, User $actor, array $limits, CarbonImmutable $at): FuelCard
    {
        return $this->refusalsAsLogicErrors('limit change', fn (): FuelCard => $this->cards->updateLimitsHistorical(
            $card,
            array_key_exists('monthly_limit_l', $limits) ? $limits['monthly_limit_l'] : $card->monthly_limit_l,
            array_key_exists('monthly_limit_usd', $limits) ? $limits['monthly_limit_usd'] : $card->monthly_limit_usd,
            $actor,
            $at,
        ));
    }

    /**
     * Record an admin's manual USD/LBP override, valid for at most 72 hours
     * and never retroactive (it is created when it takes effect).
     */
    public function createManualRate(
        User $admin,
        string $rate,
        CarbonImmutable $effectiveAt,
        CarbonImmutable $expiresAt,
        string $reason,
    ): ExchangeRate {
        if ($admin->role !== UserRole::Admin) {
            throw new LogicException('Only an admin can create a manual exchange-rate override.');
        }

        if ($expiresAt->lessThanOrEqualTo($effectiveAt) || $expiresAt->greaterThan($effectiveAt->addHours(72))) {
            throw new LogicException('A manual override must expire after it starts and within 72 hours.');
        }

        return DB::transaction(function () use ($admin, $rate, $effectiveAt, $expiresAt, $reason): ExchangeRate {
            $override = ExchangeRate::query()->forceCreate([
                'base' => 'USD',
                'quote' => 'LBP',
                'rate' => $rate,
                'source' => RateSource::Manual,
                'effective_at' => $effectiveAt->utc(),
                'fetched_at' => null,
                'expires_at' => $expiresAt->utc(),
                'created_by' => $admin->id,
                'reason' => $reason,
                'created_at' => $effectiveAt->utc(),
            ]);

            $this->audit($admin, 'exchange_rate.override_created', $override->getMorphClass(), $override->id, null, null, [
                'rate' => $override->rate,
                'effective_at' => $override->effective_at->toIso8601ZuluString(),
                'expires_at' => $override->expires_at->toIso8601ZuluString(),
                'reason' => $reason,
            ], $effectiveAt);

            return $override;
        });
    }

    /**
     * Create a pending delivery order with its initial null -> pending
     * history, through DeliveryOrderService with the clock at $createdAt.
     *
     * @param  array{address: string, governorate: string, liters: string, preferred_start_at: CarbonImmutable, preferred_end_at: CarbonImmutable}  $details
     */
    public function createDelivery(Company $company, User $creator, array $details, CarbonImmutable $createdAt): DeliveryOrder
    {
        return $this->refusalsAsLogicErrors('delivery order', fn (): DeliveryOrder => $this->deliveries->createHistorical($company, $details, $creator, $createdAt));
    }

    /**
     * Move an order along the delivery state machine with the rules live
     * requests follow (DeliveryOrderService): admins may make any allowed
     * move; a manager may only cancel their own company's pending order.
     * History and audit rows are written in the same transaction.
     *
     * @param  array{scheduled_start_at?: CarbonImmutable, scheduled_end_at?: CarbonImmutable, assigned_truck?: string, cancel_reason?: string}  $changes
     */
    public function transitionDelivery(DeliveryOrder $order, DeliveryStatus $to, User $actor, CarbonImmutable $at, array $changes = []): DeliveryOrder
    {
        return $this->refusalsAsLogicErrors('delivery transition', fn (): DeliveryOrder => $this->deliveries->transitionHistorical($order, $to, $changes, $actor, $at));
    }

    /**
     * Fixtures must describe possible history, so a refusal is a bug in the
     * fixture: report it as a LogicException with the refusal's reason.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function refusalsAsLogicErrors(string $what, Closure $write): mixed
    {
        try {
            return $write();
        } catch (BusinessRuleViolation $e) {
            throw new LogicException("Fixture {$what} refused ({$e->errorCode}): {$e->getMessage()}", 0, $e);
        } catch (ValidationException $e) {
            throw new LogicException("Fixture {$what} refused (validation_failed): ".implode(' ', Arr::flatten($e->errors())), 0, $e);
        }
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(User $actor, string $action, string $type, int $id, ?int $companyId, ?array $old, ?array $new, CarbonImmutable $at): void
    {
        AuditLog::query()->forceCreate([
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $id,
            'company_id' => $companyId,
            'old_values' => $old,
            'new_values' => $new,
            'request_id' => null,
            'created_at' => $at,
        ]);
    }
}
