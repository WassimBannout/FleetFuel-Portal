<?php

namespace App\Rules;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A non-negative decimal typed as plain digits, such as "150" or "150.25":
 * no sign, exponent, thousands separator or extra decimal places. The value
 * stays a string, so no float rounding happens before brick/math takes over.
 */
class DecimalString implements ValidationRule
{
    /**
     * @param  int  $integerDigits  Digits allowed before the point; match the column (DECIMAL(12,2) allows 10).
     * @param  bool  $allowZero  False for values that must be positive, such as a tank capacity.
     */
    public function __construct(
        private readonly int $integerDigits,
        private readonly int $scale = 2,
        private readonly bool $allowZero = true,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = sprintf('/^\d{1,%d}(\.\d{1,%d})?$/', $this->integerDigits, $this->scale);

        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $fail("The :attribute must be a plain number with up to {$this->integerDigits} digits and {$this->scale} decimal places, such as 150 or 150.25.");

            return;
        }

        if (! $this->allowZero && BigDecimal::of($value)->isZero()) {
            $fail('The :attribute must be greater than zero.');
        }
    }
}
