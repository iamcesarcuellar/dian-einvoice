<?php

namespace App\Modules\Billing\Domain;

/**
 * A document line to calculate. Decimals are strings. A line without a tax code is excluded:
 * it carries no tax at all, which is not the same as a 0 % rate.
 */
final readonly class LineInput
{
    public function __construct(
        public string $quantity,
        public string $unitPrice,
        public string $discountRate = '0',
        public ?string $taxCode = null,
        public ?string $taxRate = null,
    ) {}
}
