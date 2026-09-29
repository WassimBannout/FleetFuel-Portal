<?php

namespace Tests\Unit\Rules;

use App\Rules\DecimalString;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalStringTest extends TestCase
{
    #[DataProvider('values')]
    public function test_only_plain_decimals_within_the_column_pass(mixed $value, bool $valid): void
    {
        $this->assertSame($valid, $this->passes(new DecimalString(10), $value));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function values(): array
    {
        return [
            'integer' => ['150', true],
            'two decimals' => ['150.25', true],
            'one decimal' => ['0.5', true],
            'zero' => ['0', true],
            'ten digits' => ['9999999999.99', true],
            'eleven digits' => ['12345678901', false],
            'three decimals' => ['1.234', false],
            'negative' => ['-1', false],
            'plus sign' => ['+1', false],
            'exponent' => ['1e3', false],
            'thousands separator' => ['1,000', false],
            'leading point' => ['.5', false],
            'trailing point' => ['5.', false],
            'text' => ['abc', false],
            'a real number, not a string' => [1.5, false],
        ];
    }

    public function test_positive_values_can_exclude_zero(): void
    {
        $rule = new DecimalString(8, allowZero: false);

        $this->assertFalse($this->passes($rule, '0'));
        $this->assertFalse($this->passes($rule, '0.00'));
        $this->assertTrue($this->passes($rule, '0.01'));
    }

    private function passes(DecimalString $rule, mixed $value): bool
    {
        $passed = true;
        $translator = new Translator(new ArrayLoader, 'en');

        $rule->validate('value', $value, function (string $message, ?string $translate = null) use (&$passed, $translator): PotentiallyTranslatedString {
            $passed = false;

            return new PotentiallyTranslatedString($message, $translator);
        });

        return $passed;
    }
}
