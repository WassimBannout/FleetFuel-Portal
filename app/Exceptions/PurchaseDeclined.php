<?php

namespace App\Exceptions;

/**
 * A POS purchase the business rules refuse (docs/05-API-CONTRACT.md,
 * "Status and error codes"). Nothing is written when one is thrown: the
 * ledger and the monthly counter stay as they were.
 */
class PurchaseDeclined extends ApiException
{
    public static function stationInactive(): self
    {
        return new self(403, 'station_inactive', 'This station is inactive and cannot record purchases.');
    }

    public static function cardNotFound(): self
    {
        return new self(404, 'not_found', 'No fuel card has that number.');
    }

    public static function cardBlocked(): self
    {
        return new self(403, 'card_blocked', 'This card is blocked.');
    }

    public static function cardInactive(): self
    {
        return new self(403, 'card_inactive', 'This card is archived and can no longer be used.');
    }

    public static function companyInactive(): self
    {
        return new self(403, 'company_inactive', "The card's company is inactive.");
    }

    /** @param  'vehicle'|'driver'  $assignment */
    public static function assignmentInactive(string $assignment): self
    {
        return new self(403, 'assignment_inactive', "The card's assigned {$assignment} is inactive.", ['assignment' => $assignment]);
    }

    /** @param  'product_inactive'|'card_restriction'|'vehicle_fuel_type'  $reason */
    public static function productNotAllowed(string $reason): self
    {
        $message = match ($reason) {
            'product_inactive' => 'This product is not on sale.',
            'card_restriction' => 'This card is restricted to another product.',
            'vehicle_fuel_type' => "This product does not match the vehicle's fuel type.",
        };

        return new self(403, 'product_not_allowed', $message, ['reason' => $reason]);
    }

    /** @param  'liters'|'usd'  $dimension */
    public static function quotaExceeded(string $dimension): self
    {
        return new self(403, 'quota_exceeded', "This purchase exceeds the card's monthly quota.", ['dimension' => $dimension]);
    }
}
