<?php

namespace App\Exceptions;

use App\Enums\DeliveryStatus;

/**
 * A write refused by a business rule, thrown by services shared by the web
 * screens and the API. On /api/* it becomes the documented error envelope
 * (ApiErrorRenderer); on web pages the user is sent back to the form with
 * the message under $field (see bootstrap/app.php).
 */
class BusinessRuleViolation extends ApiException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        int $status,
        string $errorCode,
        string $message,
        public readonly string $field = 'rule',
        array $details = [],
    ) {
        parent::__construct($status, $errorCode, $message, $details);
    }

    public static function companyInactive(): self
    {
        return new self(403, 'company_inactive',
            'This company is inactive, so its fleet is read-only. Blocking or archiving cards and deactivating vehicles or drivers still work.');
    }

    public static function assignmentLocked(): self
    {
        return new self(409, 'assignment_locked',
            'This card has already been used, so its vehicle, driver and product restriction can no longer change.');
    }

    public static function cardArchived(): self
    {
        return new self(409, 'invalid_transition', 'Archived cards are final and cannot be changed.');
    }

    public static function limitBelowUsage(string $usedL, string $usedUsd): self
    {
        return new self(409, 'confirmation_required',
            "This month the card has already used {$usedL} L and {$usedUsd} USD. The new limit is lower, so the card will be over quota at once. Tick the confirmation box to apply it.",
            'confirm_below_usage');
    }

    public static function deliveryCompanyInactive(): self
    {
        return new self(403, 'company_inactive', 'This company is inactive, so no new delivery can be requested for it.');
    }

    /** A role asking for a move it can never make, e.g. a manager scheduling. */
    public static function deliveryTransitionForbidden(): self
    {
        return new self(403, 'forbidden',
            'You may not make this change. Distributor staff schedule, dispatch and deliver orders; a company manager can only cancel their own pending order.');
    }

    /** The order is no longer in the status the caller saw (expected_status). */
    public static function staleDeliveryState(DeliveryStatus $current): self
    {
        return new self(409, 'stale_state',
            "This order changed in the meantime: it is now {$current->label()}. Check its current status before trying again.",
            details: ['current_status' => $current->value]);
    }

    /** A skipped step, the same status again, or any move out of a final status. */
    public static function invalidDeliveryTransition(DeliveryStatus $from, DeliveryStatus $to): self
    {
        $why = match (true) {
            $from->isTerminal() => "{$from->label()} is final.",
            $from === $to => "it is already {$from->label()}.",
            default => 'the next allowed status is '.implode(' or ', array_map(fn (DeliveryStatus $next): string => $next->label(), $from->nextStatuses())).'.',
        };

        return new self(409, 'invalid_transition', "This order cannot move from {$from->value} to {$to->value}: {$why}",
            details: ['current_status' => $from->value]);
    }
}
