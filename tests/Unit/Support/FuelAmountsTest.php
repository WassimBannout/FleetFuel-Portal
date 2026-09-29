<?php

namespace Tests\Unit\Support;

use App\Support\FuelAmounts;
use Brick\Math\Exception\RoundingNecessaryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FuelAmountsTest extends TestCase
{
    public function test_the_documented_example_yields_1600000_lbp_and_17_88_usd(): void
    {
        $amountLbp = FuelAmounts::amountLbp('20.00', '80000.0000');

        $this->assertSame('1600000.00', $amountLbp);
        // Rounding the per-liter USD price first (0.89 × 20) would give 17.80.
        $this->assertSame('17.88', FuelAmounts::amountUsd($amountLbp, '89500.00000000'));
    }

    #[DataProvider('lbpRounding')]
    public function test_lbp_amounts_round_half_up_to_two_decimals(string $liters, string $price, string $expected): void
    {
        $this->assertSame($expected, FuelAmounts::amountLbp($liters, $price));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function lbpRounding(): array
    {
        return [
            'exactly half rounds up' => ['1.00', '0.0050', '0.01'],
            'just below half rounds down' => ['1.00', '0.0049', '0.00'],
            'tiny product rounds to zero' => ['0.01', '0.0050', '0.00'],
        ];
    }

    #[DataProvider('usdRounding')]
    public function test_usd_amounts_round_half_up_from_the_rounded_lbp_amount(string $amountLbp, string $expected): void
    {
        $this->assertSame($expected, FuelAmounts::amountUsd($amountLbp, '89500'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function usdRounding(): array
    {
        return [
            '0.005 USD rounds up' => ['447.50', '0.01'],
            'just below 0.005 USD rounds down' => ['447.49', '0.00'],
        ];
    }

    public function test_the_indicative_usd_unit_price_has_four_decimals_rounded_half_up(): void
    {
        // 80000 / 89500 = 0.893854...
        $this->assertSame('0.8939', FuelAmounts::indicativeUnitPriceUsd('80000.0000', '89500.00000000'));
        // 0.5 / 10000 = 0.00005: exactly half at the fifth decimal place.
        $this->assertSame('0.0001', FuelAmounts::indicativeUnitPriceUsd('0.5', '10000'));
        $this->assertSame('0.0000', FuelAmounts::indicativeUnitPriceUsd('0.4999', '10000'));
    }

    public function test_adding_amounts_is_exact_and_never_rounds_silently(): void
    {
        // With PHP floats 0.1 + 0.2 is 0.30000000000000004.
        $this->assertSame('0.30', FuelAmounts::add('0.10', '0.20'));

        $this->expectException(RoundingNecessaryException::class);
        FuelAmounts::add('1.005', '0');
    }
}
