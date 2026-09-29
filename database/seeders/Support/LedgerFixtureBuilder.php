<?php

namespace Database\Seeders\Support;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Enums\DeliveryStatus;
use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CardMonthlyUsage;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\ExchangeRate;
use App\Models\FuelCard;
use App\Models\FuelTransaction;
use App\Models\Product;
use App\Models\Station;
use App\Models\User;
use App\Services\PriceResolver;
use App\Support\BusinessMonth;
use App\Support\FuelAmounts;
use App\Support\PosRequestHash;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes historical demo/test data with the same rules live writes follow,
 * until the real services exist (card service M03, POS ingestion M05,
 * deliveries M07):
 *
 * - A purchase locks the card row, then the monthly usage row, inserts one
 *   ledger row with price, rate and ownership snapshots and increments the
 *   counter exactly once, all in one database transaction.
 * - It refuses what the live service would decline (inactive card, wrong
 *   fuel, over quota), so fixtures never contain impossible history.
 * - Quota changes, manual rate overrides and delivery transitions write
 *   their audit/history rows in the same transaction as the change.
 *
 * It deliberately skips request-time rules such as the 72-hour ingestion
 * window, because its purpose is to record the past.
 */
final class LedgerFixtureBuilder
{
    public function __construct(private readonly PriceResolver $prices) {}

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
        $transactedAt = $transactedAt->utc();
        $liters = (string) BigDecimal::of($liters)->toScale(2);

        return DB::transaction(function () use ($card, $station, $operator, $product, $liters, $transactedAt, $externalRef, $odometerKm): FuelTransaction {
            // Lock order: card, then monthly usage, then insert the ledger row.
            $card = FuelCard::query()
                ->with(['company', 'vehicle', 'driver'])
                ->lockForUpdate()
                ->findOrFail($card->id);

            $this->assertPurchaseAllowed($card, $station, $operator, $product);

            // Demo history is synthetic, so it is priced in fixture mode
            // whatever EXCHANGE_RATE_MODE says (live mode ignores fixtures).
            $quote = $this->prices->quote($product, $liters, $transactedAt, RateMode::Fixture);
            $price = $quote->price;
            $rate = $quote->rate;
            $amountLbp = $quote->amountLbp;
            $amountUsd = $quote->amountUsd;
            $quotaMonth = BusinessMonth::for($transactedAt);

            $usage = $this->lockedUsage($card, $quotaMonth);
            $this->assertWithinQuota($card, $usage, $liters, $amountUsd);

            $externalRef = strtoupper($externalRef);

            $transaction = FuelTransaction::query()->forceCreate([
                'fuel_card_id' => $card->id,
                'station_id' => $station->id,
                'external_ref' => $externalRef,
                'request_hash' => PosRequestHash::make(
                    $station->id, $externalRef, $card->card_no, $product->code, $liters, $transactedAt, $odometerKm,
                ),
                'company_id' => $card->company_id,
                'vehicle_id' => $card->vehicle_id,
                'driver_id' => $card->driver_id,
                'tank_capacity_l' => $card->vehicle?->tank_capacity_l,
                'product_id' => $product->id,
                'liters' => $liters,
                'odometer_km' => $odometerKm,
                'product_price_id' => $price->id,
                'unit_price_lbp' => $price->price_lbp,
                'amount_lbp' => $amountLbp,
                'exchange_rate_id' => $rate->id,
                'rate_lbp_per_usd' => $rate->rate,
                'rate_source' => $rate->source,
                'rate_effective_at' => $rate->effective_at,
                'amount_usd' => $amountUsd,
                'transacted_at' => $transactedAt,
                'quota_month' => $quotaMonth,
                'created_by' => $operator->id,
                // Received a minute after the pump event.
                'created_at' => $transactedAt->addMinute(),
            ]);

            $usage->used_l = FuelAmounts::add($usage->used_l, $liters);
            $usage->used_usd = FuelAmounts::add($usage->used_usd, $amountUsd);
            $usage->save();

            return $transaction;
        });
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

    private function assertPurchaseAllowed(FuelCard $card, Station $station, User $operator, Product $product): void
    {
        $refuse = fn (string $reason) => throw new LogicException("Fixture purchase on {$card->card_no} refused: {$reason}.");

        if ($card->status !== CardStatus::Active) {
            $refuse("card is {$card->status->value}");
        }
        if ($card->company->status !== CompanyStatus::Active) {
            $refuse('company is inactive');
        }
        if (! $station->is_active) {
            $refuse('station is inactive');
        }
        if ($operator->role !== UserRole::StationOperator || $operator->station_id !== $station->id) {
            $refuse('the recording user is not an operator of that station');
        }
        if (! $product->is_active) {
            $refuse('product is inactive');
        }
        if ($card->allowed_product_id !== null && $card->allowed_product_id !== $product->id) {
            $refuse("card is restricted to another product than {$product->code}");
        }
        if ($card->vehicle !== null && ! $card->vehicle->is_active) {
            $refuse('assigned vehicle is inactive');
        }
        if ($card->vehicle !== null && $card->vehicle->fuel_type !== $product->fuel_type) {
            $refuse("vehicle takes {$card->vehicle->fuel_type->value}, not {$product->code}");
        }
        if ($card->driver !== null && ! $card->driver->is_active) {
            $refuse('assigned driver is inactive');
        }
    }

    private function assertWithinQuota(FuelCard $card, CardMonthlyUsage $usage, string $liters, string $amountUsd): void
    {
        if ($card->monthly_limit_l !== null && BigDecimal::of($usage->used_l)->plus($liters)->isGreaterThan($card->monthly_limit_l)) {
            throw new LogicException("Fixture purchase on {$card->card_no} would exceed its liter quota.");
        }

        if ($card->monthly_limit_usd !== null && BigDecimal::of($usage->used_usd)->plus($amountUsd)->isGreaterThan($card->monthly_limit_usd)) {
            throw new LogicException("Fixture purchase on {$card->card_no} would exceed its USD quota.");
        }
    }

    private function lockedUsage(FuelCard $card, string $month): CardMonthlyUsage
    {
        $usage = CardMonthlyUsage::query()
            ->where('fuel_card_id', $card->id)
            ->where('month_start', $month)
            ->lockForUpdate()
            ->first();

        // A freshly inserted row is already locked by this transaction.
        return $usage ?? CardMonthlyUsage::query()->forceCreate([
            'fuel_card_id' => $card->id,
            'month_start' => $month,
        ]);
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
