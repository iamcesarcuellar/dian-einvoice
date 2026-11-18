<?php

namespace App\Modules\Billing\Domain;

/**
 * A withholding to calculate, as a percentage of its base.
 */
final readonly class WithholdingInput
{
    public function __construct(
        public string $rate,
        /** True for ReteIVA, calculated on the IVA; false for ReteFuente and ReteICA, on the line values. */
        public bool $onTax,
    ) {}
}
