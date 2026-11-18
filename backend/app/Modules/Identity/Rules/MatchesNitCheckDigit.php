<?php

namespace App\Modules\Identity\Rules;

use App\Modules\Identity\Support\NitCheckDigit;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * Rejects a check digit that differs from the one computed for the NIT sent in the same request,
 * or for the stored NIT when the request does not change it.
 */
final class MatchesNitCheckDigit implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly string $nitAttribute,
        private readonly ?string $storedNit = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $nit = Arr::get($this->data, $this->nitAttribute, $this->storedNit);

        // An invalid NIT is reported by its own rules.
        if (! is_string($nit) || preg_match('/^\d{1,15}$/', $nit) !== 1) {
            return;
        }

        if ((int) $value !== NitCheckDigit::calculate($nit)) {
            $fail('The check digit does not match the NIT.');
        }
    }
}
