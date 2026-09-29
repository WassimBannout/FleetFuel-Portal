<?php

namespace App\Exceptions;

/**
 * A write refused by a business rule, thrown by services shared by the web
 * screens and the API. On /api/* it becomes the documented error envelope
 * (ApiErrorRenderer); on web pages the user is sent back to the form with
 * the message under $field (see bootstrap/app.php).
 */
class BusinessRuleViolation extends ApiException
{
    public function __construct(
        int $status,
        string $errorCode,
        string $message,
        public readonly string $field = 'rule',
    ) {
        parent::__construct($status, $errorCode, $message);
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
}
