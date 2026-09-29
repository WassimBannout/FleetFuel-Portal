<?php

namespace Database\Seeders\Support;

use App\Enums\DeliveryStatus;
use App\Enums\RateSource;
use App\Enums\UserRole;
use App\Exceptions\PurchaseDeclined;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Services\FuelTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
 * - Quota changes, manual rate overrides and delivery transitions write
 *   their audit/history rows in the same transaction as the change (the
 *   delivery service arrives in M07).
 */
final class LedgerFixtureBuilder
{
    public function __construct(private readonly FuelTransactionService $transactions) {}

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
     * Change a card's monthly limits under the card lock and audit the old and
     * new values of the fields that changed.
     *
     * @param  array{monthly_limit_l?: ?string, monthly_limit_usd?: ?string}  $limits
     */
    public function changeMonthlyLimits(FuelCard $card, User $actor, array $limits, CarbonImmutable $at): FuelCard
    {
        return DB::transaction(function () use ($card, $actor, $limits, $at): FuelCard {
            $card = FuelCard::query()->lockForUpdate()->findOrFail($card->id);

            $old = [];
            foreach ($limits as $field => $value) {
                $old[$field] = $card->{$field};
                $card->{$field} = $value;
            }

            $card->updated_at = $at;
            $card->save();

            $this->audit($actor, 'fuel_card.limits_changed', $card->getMorphClass(), $card->id, $card->company_id, $old, $limits, $at);

            return $card;
        });
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
     * Create a pending delivery order with its initial null -> pending history.
     *
     * @param  array{address: string, governorate: string, liters: string, preferred_start_at: CarbonImmutable, preferred_end_at: CarbonImmutable}  $details
     */
    public function createDelivery(Company $company, User $creator, array $details, CarbonImmutable $createdAt): DeliveryOrder
    {
        $isOwnManager = $creator->role === UserRole::CompanyManager && $creator->company_id === $company->id;

        if ($creator->role !== UserRole::Admin && ! $isOwnManager) {
            throw new LogicException('Only an admin or the company\'s own manager can create its delivery order.');
        }

        return DB::transaction(function () use ($company, $creator, $details, $createdAt): DeliveryOrder {
            $order = DeliveryOrder::query()->forceCreate($details + [
                'company_id' => $company->id,
                'created_by' => $creator->id,
                'status' => DeliveryStatus::Pending,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            DeliveryStatusHistory::query()->forceCreate([
                'delivery_order_id' => $order->id,
                'from_status' => null,
                'to_status' => DeliveryStatus::Pending,
                'changed_by' => $creator->id,
                'changed_at' => $createdAt,
            ]);

            return $order;
        });
    }

    /**
     * Move an order along the delivery state machine, appending history and
     * audit rows. Admins may make any allowed transition; a manager may only
     * cancel their own company's pending order.
     *
     * @param  array<string, mixed>  $changes  e.g. schedule window and truck, or cancel_reason
     */
    public function transitionDelivery(
        DeliveryOrder $order,
        DeliveryStatus $to,
        User $actor,
        CarbonImmutable $at,
        array $changes = [],
        ?string $note = null,
    ): DeliveryOrder {
        return DB::transaction(function () use ($order, $to, $actor, $at, $changes, $note): DeliveryOrder {
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if (! $from->canTransitionTo($to)) {
                throw new LogicException("A delivery cannot move from {$from->value} to {$to->value}.");
            }

            $isOwnManagerCancelling = $actor->role === UserRole::CompanyManager
                && $actor->company_id === $order->company_id
                && $from === DeliveryStatus::Pending
                && $to === DeliveryStatus::Cancelled;

            if ($actor->role !== UserRole::Admin && ! $isOwnManagerCancelling) {
                throw new LogicException('This user may not make that delivery transition.');
            }

            if ($to === DeliveryStatus::Delivered) {
                $changes['delivered_at'] = $at;
            }

            $order->forceFill($changes + ['status' => $to, 'updated_at' => $at])->save();

            DeliveryStatusHistory::query()->forceCreate([
                'delivery_order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $actor->id,
                'note' => $note,
                'changed_at' => $at,
            ]);

            $this->audit($actor, 'delivery_order.status_changed', $order->getMorphClass(), $order->id, $order->company_id,
                ['status' => $from->value],
                ['status' => $to->value] + array_map(
                    fn (mixed $value): mixed => $value instanceof CarbonImmutable ? $value->toIso8601ZuluString() : $value,
                    $changes,
                ),
                $at,
            );

            return $order;
        });
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
