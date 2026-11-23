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
            $fail(__('rules.fiscal_responsibilities.empty'));

            return;
        }

        foreach ($value as $code) {
            if (! is_string($code) || FiscalResponsibility::tryFrom($code) === null) {
                $fail(__('rules.fiscal_responsibilities.unknown'));

                return;
            }
        }

        if (count($value) !== count(array_unique($value))) {
            $fail(__('rules.fiscal_responsibilities.repeated'));

            return;
        }

        if (count($value) > 1 && in_array(FiscalResponsibility::NotApplicable->value, $value, true)) {
            $fail(__('rules.fiscal_responsibilities.not_applicable'));
        }
    }
}
