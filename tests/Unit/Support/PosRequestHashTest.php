<?php

namespace Tests\Unit\Support;

use App\Support\PosRequestHash;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PosRequestHashTest extends TestCase
{
    public function test_equivalent_requests_share_a_hash(): void
    {
        $equivalent = $this->hash([
            'externalRef' => 'pos-demo-0001',
            'cardNo' => 'ff-atlas-001',
            'productCode' => 'diesel',
            'liters' => '20',
            // 10:00 in Beirut (UTC+3) is 07:00 UTC.
            'transactedAt' => CarbonImmutable::parse('2026-09-28T07:00:00Z'),
        ]);

        $this->assertSame($this->hash(), $equivalent);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $equivalent);
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('meaningfulChanges')]
    public function test_any_meaningful_change_changes_the_hash(array $change): void
    {
        $this->assertNotSame($this->hash(), $this->hash($change));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function meaningfulChanges(): array
    {
        return [
            'liters' => [['liters' => '20.01']],
            'odometer removed' => [['odometerKm' => null]],
            'station' => [['stationId' => 8]],
            'external ref' => [['externalRef' => 'POS-DEMO-0002']],
            'event time' => [['transactedAt' => CarbonImmutable::parse('2026-09-28T10:00:01+03:00')]],
            'product' => [['productCode' => 'ULP95']],
            'card' => [['cardNo' => 'FF-ATLAS-TINY']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function hash(array $overrides = []): string
    {
        return PosRequestHash::make(...[
            'stationId' => 7,
            'externalRef' => 'POS-DEMO-0001',
            'cardNo' => 'FF-ATLAS-001',
            'productCode' => 'DIESEL',
            'liters' => '20.00',
            'transactedAt' => CarbonImmutable::parse('2026-09-28T10:00:00+03:00'),
            'odometerKm' => 45000,
            ...$overrides,
        ]);
    }
}
