<?php

namespace Tests\Unit\Support;

use App\Support\CsvCell;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvCellTest extends TestCase
{
    /**
     * @return array<string, array{?string, string}>
     */
    public static function cells(): array
    {
        return [
            'equals' => ['=HYPERLINK("http://x.test","click")', '\'=HYPERLINK("http://x.test","click")'],
            'plus' => ['+961 1 000000', "'+961 1 000000"],
            'minus' => ['-2+3', "'-2+3"],
            'at' => ['@SUM(A1:A2)', "'@SUM(A1:A2)"],
            'after spaces' => ['   =1+1', "'   =1+1"],
            'tab' => ["\t=1", "'\t=1"],
            'carriage return' => ["\r=1", "'\r=1"],
            'line feed' => ["\n=1", "'\n=1"],
            'plain text' => ['Atlas Logistics', 'Atlas Logistics'],
            'sign inside' => ['ATL-101', 'ATL-101'],
            'comma and quote are left to fputcsv' => ['Depot "A", Beirut', 'Depot "A", Beirut'],
            'null' => [null, ''],
        ];
    }

    #[DataProvider('cells')]
    public function test_formula_prefixes_are_neutralized(?string $value, string $expected): void
    {
        $this->assertSame($expected, CsvCell::text($value));
    }
}
