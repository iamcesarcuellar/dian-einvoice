<?php

namespace App\Modules\Reference\Rules;

use App\Modules\Reference\Enums\FiscalResponsibility;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A list of fiscal responsibility codes: at least one, no repeats, and R-99-PN (not applicable)
 * only on its own.
 */
final class ValidFiscalResponsibilities implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            $fail('The :attribute must list at least one fiscal responsibility.');

            return;
        }

        foreach ($value as $code) {
            if (! is_string($code) || FiscalResponsibility::tryFrom($code) === null) {
                $fail('The :attribute contains an unknown code.');

                return;
            }
        }

        if (count($value) !== count(array_unique($value))) {
            $fail('The :attribute contains repeated codes.');

            return;
        }

        if (count($value) > 1 && in_array(FiscalResponsibility::NotApplicable->value, $value, true)) {
            $fail('R-99-PN (not applicable) cannot be combined with other fiscal responsibilities.');
        }
    }
}
