<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\DeliveryStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\DeliveryStatusHistory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Diesel delivery orders and their state machine (docs/04-BUSINESS-RULES.md,
 * "Delivery state machine"), shared by the web screens, the API and the
 * demo seed.
 *
 * A status change is one database transaction: lock the order row
 * (SELECT ... FOR UPDATE), then check who is asking, whether the order is
 * still in the status the caller saw (expected_status), whether the move is
 * allowed and whether its details are valid. Only then is the order updated
 * and exactly one history row and one audit row appended. Two requests that
 * saw the same status queue on the lock; the second one finds the status
 * changed (409 stale_state), so only one of them changes the order.
 *
 * Deliveries never touch fuel cards, quotas or the POS ledger.
 */
class DeliveryOrderService
{
    /** Preferred and scheduled windows must start within this many days. */
    public const HORIZON_DAYS = 366;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * A new pending order with its initial null -> pending history row.
     *
     * @param  array{address: string, governorate: string, liters: string, preferred_start_at: CarbonImmutable, preferred_end_at: CarbonImmutable}  $details
     */
    public function create(Company $company, array $details, User $actor): DeliveryOrder
    {
        return $this->createAt($company, $details, $actor, CarbonImmutable::now());
    }

    /**
     * Demo and test history: the same rules as create(), judged at $at
     * instead of now.
     *
     * @param  array{address: string, governorate: string, liters: string, preferred_start_at: CarbonImmutable, preferred_end_at: CarbonImmutable}  $details
     */
    public function createHistorical(Company $company, array $details, User $actor, CarbonImmutable $at): DeliveryOrder
    {
        return $this->createAt($company, $details, $actor, $at);
    }

    /**
     * Move the order from $expected to $to. $details carries what the target
     * status needs: scheduled_start_at, scheduled_end_at and assigned_truck
     * for scheduled; cancel_reason for cancelled; nothing otherwise.
     *
     * @param  array{scheduled_start_at?: CarbonImmutable, scheduled_end_at?: CarbonImmutable, assigned_truck?: string, cancel_reason?: string}  $details
     */
    public function transition(DeliveryOrder $order, DeliveryStatus $expected, DeliveryStatus $to, array $details, User $actor): DeliveryOrder
    {
        return $this->transitionAt($order, $expected, $to, $details, $actor, CarbonImmutable::now());
    }

    /**
     * Demo and test history: the same rules as transition(), judged at $at,
     * without an expected status (nothing else is changing the order).
     *
     * @param  array{scheduled_start_at?: CarbonImmutable, scheduled_end_at?: CarbonImmutable, assigned_truck?: string, cancel_reason?: string}  $details
     */
    public function transitionHistorical(DeliveryOrder $order, DeliveryStatus $to, array $details, User $actor, CarbonImmutable $at): DeliveryOrder
    {
        return $this->transitionAt($order, null, $to, $details, $actor, $at);
    }

    /**
     * @param  array{address: string, governorate: string, liters: string, preferred_start_at: CarbonImmutable, preferred_end_at: CarbonImmutable}  $details
     */
    private function createAt(Company $company, array $details, User $actor, CarbonImmutable $at): DeliveryOrder
    {
        $at = $at->utc()->startOfSecond();

        if (! $actor->is_active || ! ($actor->isAdmin() || $actor->managesCompany($company->id))) {
            throw new BusinessRuleViolation(403, 'forbidden', 'Only an admin or the company\'s own manager can request its deliveries.');
        }

        if ($company->status !== CompanyStatus::Active) {
            throw BusinessRuleViolation::deliveryCompanyInactive();
        }

        $this->ensureWindow('preferred', $details['preferred_start_at'], $details['preferred_end_at'], $at);

        $order = DB::transaction(function () use ($company, $details, $actor, $at): DeliveryOrder {
            $order = new DeliveryOrder;
            $order->forceFill([
                'company_id' => $company->id,
                'created_by' => $actor->id,
                'address' => $details['address'],
                'governorate' => $details['governorate'],
                'liters' => $details['liters'],
                'preferred_start_at' => $details['preferred_start_at']->utc(),
                'preferred_end_at' => $details['preferred_end_at']->utc(),
                'status' => DeliveryStatus::Pending,
                'created_at' => $at,
                'updated_at' => $at,
            ])->save();

            DeliveryStatusHistory::query()->forceCreate([
                'delivery_order_id' => $order->id,
                'from_status' => null,
                'to_status' => DeliveryStatus::Pending,
                'changed_by' => $actor->id,
                'changed_at' => $at,
            ]);

            return $order;
        });

        return $order->refresh()->load('statusHistory');
    }

    /**
     * @param  array{scheduled_start_at?: CarbonImmutable, scheduled_end_at?: CarbonImmutable, assigned_truck?: string, cancel_reason?: string}  $details
     */
    private function transitionAt(DeliveryOrder $order, ?DeliveryStatus $expected, DeliveryStatus $to, array $details, User $actor, CarbonImmutable $at): DeliveryOrder
    {
        $at = $at->utc()->startOfSecond();

        $order = DB::transaction(function () use ($order, $expected, $to, $details, $actor, $at): DeliveryOrder {
            // Everything below reads the order as it is now, and nobody else
            // can change it until this transaction ends.
            $order = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            $from = $order->status;

            if (Gate::forUser($actor)->denies('transition', [$order, $expected ?? $from, $to])) {
                throw BusinessRuleViolation::deliveryTransitionForbidden();
            }

            if ($expected !== null && $from !== $expected) {
                throw BusinessRuleViolation::staleDeliveryState($from);
            }

            if (! $from->canTransitionTo($to)) {
                throw BusinessRuleViolation::invalidDeliveryTransition($from, $to);
            }

            $changes = $this->changesFor($order, $to, $details, $at);
            $order->forceFill($changes + ['status' => $to, 'updated_at' => $at])->save();

            DeliveryStatusHistory::query()->forceCreate([
                'delivery_order_id' => $order->id,
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $actor->id,
                'changed_at' => $at,
            ]);

            $this->audit->record('delivery_order.status_changed', $order, $actor, $order->company_id,
                ['status' => $from->value],
                ['status' => $to->value] + array_map(
                    fn (string|CarbonImmutable $value): string => $value instanceof CarbonImmutable ? $value->toIso8601ZuluString() : $value,
                    $changes,
                ),
                $at,
            );

            return $order;
        });

        return $order->refresh()->load('statusHistory');
    }

    /**
     * The fields the target status sets, after checking its details.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string|CarbonImmutable>
     */
    private function changesFor(DeliveryOrder $order, DeliveryStatus $to, array $details, CarbonImmutable $at): array
    {
        $allowed = match ($to) {
            DeliveryStatus::Scheduled => ['scheduled_start_at', 'scheduled_end_at', 'assigned_truck'],
            DeliveryStatus::Cancelled => ['cancel_reason'],
            default => [],
        };

        $unrelated = array_diff(array_keys($details), $allowed);
        if ($unrelated !== []) {
            throw new LogicException('Details not used by a move to '.$to->value.': '.implode(', ', $unrelated).'.');
        }

        return match ($to) {
            DeliveryStatus::Scheduled => $this->schedule($details, $at),
            DeliveryStatus::OutForDelivery => $this->dispatch($order),
            // Server time, set once: delivered is final.
            DeliveryStatus::Delivered => ['delivered_at' => $at],
            DeliveryStatus::Cancelled => ['cancel_reason' => $this->cancelReason($details)],
            DeliveryStatus::Pending => throw new LogicException('No move leads back to pending.'),
        };
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{scheduled_start_at: CarbonImmutable, scheduled_end_at: CarbonImmutable, assigned_truck: string}
     */
    private function schedule(array $details, CarbonImmutable $at): array
    {
        $start = $details['scheduled_start_at'] ?? null;
        $end = $details['scheduled_end_at'] ?? null;
        $truck = trim((string) ($details['assigned_truck'] ?? ''));

        $missing = array_filter([
            'scheduled_start_at' => $start instanceof CarbonImmutable ? null : 'Choose when the delivery window starts.',
            'scheduled_end_at' => $end instanceof CarbonImmutable ? null : 'Choose when the delivery window ends.',
            'assigned_truck' => $truck !== '' ? null : 'Assign a truck.',
        ]);

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        assert($start instanceof CarbonImmutable && $end instanceof CarbonImmutable);
        $this->ensureWindow('scheduled', $start, $end, $at);

        return [
            'scheduled_start_at' => $start->utc(),
            'scheduled_end_at' => $end->utc(),
            'assigned_truck' => $truck,
        ];
    }

    /**
     * Dispatch needs the schedule already in place. The state machine and a
     * database CHECK guarantee it; this makes the rule explicit.
     *
     * @return array{}
     */
    private function dispatch(DeliveryOrder $order): array
    {
        if ($order->scheduled_start_at === null || $order->scheduled_end_at === null || $order->assigned_truck === null) {
            throw new BusinessRuleViolation(409, 'invalid_transition', 'This order has no delivery window or truck yet, so it cannot be dispatched.');
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function cancelReason(array $details): string
    {
        $reason = trim((string) ($details['cancel_reason'] ?? ''));

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for the cancellation.']);
        }

        return $reason;
    }

    /**
     * A window must start in the future, within the horizon, and end after
     * it starts. Errors name the fields of the form or API body.
     */
    private function ensureWindow(string $kind, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $at): void
    {
        $label = ucfirst($kind);
        $errors = [];

        if ($start->lessThanOrEqualTo($at)) {
            $errors["{$kind}_start_at"] = "{$label} start must be in the future.";
        } elseif ($start->greaterThan($at->addDays(self::HORIZON_DAYS))) {
            $errors["{$kind}_start_at"] = "{$label} start must be within the next ".self::HORIZON_DAYS.' days.';
        }

        if ($end->lessThanOrEqualTo($start)) {
            $errors["{$kind}_end_at"] = "{$label} end must be after its start.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
