<?php

namespace Tests\Unit\Support;

use App\Support\Display;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DisplayTest extends TestCase
{
    #[DataProvider('decimals')]
    public function test_decimals_are_grouped_without_float_conversion(string $value, int $scale, string $expected): void
    {
        $this->assertSame($expected, Display::decimal($value, $scale));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function decimals(): array
    {
        return [
            'LBP amount' => ['1600000.00', 2, '1,600,000.00'],
            'half-up rounding' => ['17.875', 2, '17.88'],
            'small value' => ['999', 2, '999.00'],
            'unit price scale' => ['80000', 4, '80,000.0000'],
            'zero' => ['0', 2, '0.00'],
            'negative' => ['-1234.5', 2, '-1,234.50'],
            'beyond float precision' => ['12345678901234567.89', 2, '12,345,678,901,234,567.89'],
        ];
    }

    public function test_instants_are_shown_in_beirut_time_across_daylight_saving(): void
    {
        // Beirut is UTC+3 in summer time and UTC+2 in winter time.
        $this->assertSame('2026-09-28 10:00', Display::businessTime(CarbonImmutable::parse('2026-09-28T07:00:00Z'), 'Asia/Beirut'));
        $this->assertSame('2026-12-01 09:00', Display::businessTime(CarbonImmutable::parse('2026-12-01T07:00:00Z'), 'Asia/Beirut'));
    }
}
