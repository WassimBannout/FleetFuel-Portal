<?php

namespace Tests\Unit\Support;

use App\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T09 storage bounds: a value that would overflow or lose decimals in its
 * DECIMAL column is detected before anything is written.
 */
class DecimalTest extends TestCase
{
    #[DataProvider('bounds')]
    public function test_fits_checks_integer_digits_and_scale(string $value, int $precision, int $scale, bool $expected): void
    {
        $this->assertSame($expected, Decimal::fits($value, $precision, $scale));
    }

    /**
     * @return array<string, array{string, int, int, bool}>
     */
    public static function bounds(): array
    {
        return [
            'largest DECIMAL(20,2)' => ['999999999999999999.99', 20, 2, true],
            'one cent over DECIMAL(20,2)' => ['1000000000000000000.00', 20, 2, false],
            'largest DECIMAL(18,2)' => ['9999999999999999.99', 18, 2, true],
            'overflow DECIMAL(18,2)' => ['10000000000000000', 18, 2, false],
            'excess decimal place' => ['17.885', 18, 2, false],
            'trailing zeros are not excess' => ['17.8800', 18, 2, true],
            'eight-decimal rate in DECIMAL(20,8)' => ['89500.12345678', 20, 8, true],
            'nine-decimal rate' => ['89500.123456789', 20, 8, false],
            'negative within bounds' => ['-5.00', 10, 2, true],
        ];
    }

    public function test_normalize_pads_to_the_scale_and_keeps_null(): void
    {
        $this->assertSame('20.00', Decimal::normalize('20'));
        $this->assertSame('80000.0000', Decimal::normalize('80000', 4));
        $this->assertNull(Decimal::normalize(null));
    }
}
