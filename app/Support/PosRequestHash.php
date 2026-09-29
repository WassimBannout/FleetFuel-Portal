<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Fingerprint of a POS purchase request, used for idempotent retries.
 *
 * The request is reduced to a canonical form first: identifiers uppercased,
 * liters at scale 2, event time in UTC, odometer null when absent. So JSON
 * key order, "20" versus "20.00" or "+03:00" versus "Z" for the same instant
 * produce the same hash, while any real change produces a different one.
 */
final class PosRequestHash
{
    public static function make(
        int $stationId,
        string $externalRef,
        string $cardNo,
        string $productCode,
        string $liters,
        CarbonInterface $transactedAt,
        ?int $odometerKm,
    ): string {
        $canonical = [
            'card_no' => strtoupper($cardNo),
            'external_ref' => strtoupper($externalRef),
            'liters' => (string) BigDecimal::of($liters)->toScale(2),
            'odometer_km' => $odometerKm,
            'product_code' => strtoupper($productCode),
            'station_id' => $stationId,
            'transacted_at' => CarbonImmutable::instance($transactedAt)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
