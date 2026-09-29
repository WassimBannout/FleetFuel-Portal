<?php

namespace Tests\Unit\Support;

use App\Support\BusinessMonth;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BusinessMonthTest extends TestCase
{
    private const BEIRUT = 'Asia/Beirut';

    #[DataProvider('instants')]
    public function test_quota_month_follows_the_beirut_calendar(string $instant, string $expectedMonth): void
    {
        $this->assertSame($expectedMonth, BusinessMonth::for(CarbonImmutable::parse($instant), self::BEIRUT));
    }

    /**
     * Beirut is UTC+3 in summer and UTC+2 after daylight saving ends on the
     * last Sunday of October (25 October 2026).
     *
     * @return array<string, array{string, string}>
     */
    public static function instants(): array
    {
        return [
            'last second of September in Beirut' => ['2026-09-30T20:59:59Z', '2026-09-01'],
            'midnight of 1 October in Beirut' => ['2026-09-30T21:00:00Z', '2026-10-01'],
            'same instant written with an offset' => ['2026-10-01T00:00:00+03:00', '2026-10-01'],
            'last second of October, after DST ended' => ['2026-10-31T21:59:59Z', '2026-10-01'],
            'midnight of 1 November in Beirut' => ['2026-10-31T22:00:00Z', '2026-11-01'],
        ];
    }

    public function test_month_start_in_utc_follows_daylight_saving(): void
    {
        $this->assertSame('2026-09-30T21:00:00Z', BusinessMonth::startUtc('2026-10-01', self::BEIRUT)->toIso8601ZuluString());
        $this->assertSame('2026-10-31T22:00:00Z', BusinessMonth::startUtc('2026-11-01', self::BEIRUT)->toIso8601ZuluString());
    }
}
