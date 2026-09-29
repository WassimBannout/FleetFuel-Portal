<?php

namespace App\Support;

use App\Models\CardMonthlyUsage;
use App\Models\FuelCard;
use Brick\Math\BigDecimal;

/**
 * A card's limits and usage for one Beirut quota month. Limits are the
 * card's current ones (docs/04-BUSINESS-RULES.md): a null limit is
 * unlimited, a zero limit allows no further spend, and remaining never goes
 * below zero even when a quota was cut below usage.
 */
final readonly class CardBalance
{
    public function __construct(
        public string $month,
        public ?string $limitL,
        public ?string $limitUsd,
        public string $usedL,
        public string $usedUsd,
    ) {}

    public static function for(FuelCard $card, ?CardMonthlyUsage $usage, string $month): self
    {
        return new self(
            $month,
            $card->monthly_limit_l,
            $card->monthly_limit_usd,
            $usage->used_l ?? '0.00',
            $usage->used_usd ?? '0.00',
        );
    }

    public function remainingL(): ?string
    {
        return self::remaining($this->limitL, $this->usedL);
    }

    public function remainingUsd(): ?string
    {
        return self::remaining($this->limitUsd, $this->usedUsd);
    }

    /** True when usage already exceeds a limit, which happens only after a quota cut. */
    public function isOverQuota(): bool
    {
        return self::exceeds($this->usedL, $this->limitL) || self::exceeds($this->usedUsd, $this->limitUsd);
    }

    private static function remaining(?string $limit, string $used): ?string
    {
        if ($limit === null) {
            return null;
        }

        $left = BigDecimal::of($limit)->minus($used);

        return (string) ($left->isNegative() ? BigDecimal::zero() : $left)->toScale(2);
    }

    private static function exceeds(string $used, ?string $limit): bool
    {
        return $limit !== null && BigDecimal::of($used)->isGreaterThan($limit);
    }
}
