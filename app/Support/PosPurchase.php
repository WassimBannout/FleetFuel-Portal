<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A structurally valid POS purchase request in canonical form: identifiers
 * uppercased, liters at scale 2, event time in UTC with whole seconds, and
 * a null odometer when it was omitted. The station is not part of it; it
 * always comes from the authenticated operator.
 */
final readonly class PosPurchase
{
    public function __construct(
        public string $externalRef,
        public string $cardNo,
        public string $productCode,
        public string $liters,
        public CarbonImmutable $transactedAt,
        public ?int $odometerKm,
    ) {}

    public static function normalized(
        string $externalRef,
        string $cardNo,
        string $productCode,
        string $liters,
        CarbonImmutable $transactedAt,
        ?int $odometerKm,
    ): self {
        return new self(
            strtoupper($externalRef),
            strtoupper($cardNo),
            strtoupper($productCode),
            (string) Decimal::normalize($liters),
            $transactedAt->utc()->startOfSecond(),
            $odometerKm,
        );
    }

    /** The idempotency fingerprint for this purchase at a station. */
    public function requestHash(int $stationId): string
    {
        return PosRequestHash::make(
            $stationId, $this->externalRef, $this->cardNo, $this->productCode,
            $this->liters, $this->transactedAt, $this->odometerKm,
        );
    }
}
