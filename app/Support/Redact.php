<?php

namespace App\Support;

/**
 * Masks personal data and card identifiers before they reach logs or
 * screens that do not need the full value. Credentials are never passed
 * here: they are simply never logged.
 */
final class Redact
{
    /** "manager.atlas@fleetfuel.test" becomes "m***@fleetfuel.test". */
    public static function email(?string $email): string
    {
        $email = trim((string) $email);
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    /** "FF-ATLAS-001" becomes "••••-001": only the last four characters stay visible. */
    public static function cardNumber(string $cardNo): string
    {
        return '••••'.mb_substr($cardNo, -4);
    }
}
