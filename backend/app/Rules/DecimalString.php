<?php

namespace App\Rules;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A non-negative decimal sent as a string, such as "1234.50". Money, quantities and rates never
 * travel as floats; integers are accepted too.
 */
final class DecimalString implements ValidationRule
{
    public function __construct(
        private readonly int $integerDigits,
        private readonly int $scale,
        private readonly ?string $max = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail(__('rules.decimal.string'));

            return;
        }

        $pattern = sprintf('/^\d{1,%d}(\.\d{1,%d})?$/', $this->integerDigits, $this->scale);

        if (preg_match($pattern, (string) $value) !== 1) {
            $fail(__('rules.decimal.number', ['scale' => $this->scale]));

            return;
        }

        if ($this->max !== null && BigDecimal::of((string) $value)->isGreaterThan($this->max)) {
            $fail(__('rules.decimal.max', ['max' => $this->max]));
        }
    }
}
