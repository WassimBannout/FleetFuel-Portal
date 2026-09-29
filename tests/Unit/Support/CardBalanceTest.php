<?php

namespace Tests\Unit\Support;

use App\Support\CardBalance;
use PHPUnit\Framework\TestCase;

class CardBalanceTest extends TestCase
{
    public function test_remaining_is_limit_minus_usage(): void
    {
        $balance = new CardBalance('2026-09-01', '100.00', '100.00', '80.00', '17.88');

        $this->assertSame('20.00', $balance->remainingL());
        $this->assertSame('82.12', $balance->remainingUsd());
        $this->assertFalse($balance->isOverQuota());
    }

    public function test_a_null_limit_is_unlimited(): void
    {
        $balance = new CardBalance('2026-09-01', null, null, '5000.00', '999.99');

        $this->assertNull($balance->remainingL());
        $this->assertNull($balance->remainingUsd());
        $this->assertFalse($balance->isOverQuota());
    }

    public function test_after_a_cut_below_usage_remaining_is_zero_and_the_card_is_over_quota(): void
    {
        $balance = new CardBalance('2026-09-01', '200.00', null, '250.00', '0.00');

        $this->assertSame('0.00', $balance->remainingL());
        $this->assertTrue($balance->isOverQuota());
    }

    public function test_a_zero_limit_is_not_unlimited_and_using_exactly_the_limit_is_not_over(): void
    {
        $this->assertSame('0.00', (new CardBalance('2026-09-01', '0.00', null, '0.00', '0.00'))->remainingL());
        $this->assertFalse((new CardBalance('2026-09-01', '100.00', '17.88', '100.00', '17.88'))->isOverQuota());
    }
}
