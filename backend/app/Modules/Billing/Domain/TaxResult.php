<?php

namespace App\Modules\Billing\Domain;

use Brick\Math\BigDecimal;

/**
 * Tax subtotal of the document for one tax code and rate.
 */
final readonly class TaxResult
{
    public function __construct(
        public string $code,
        public BigDecimal $rate,
        /** Sum of the values of the lines in the group. */
        public BigDecimal $taxableAmount,
        /** Sum of the taxes of those lines, never recalculated from the taxable amount. */
        public BigDecimal $taxAmount,
    ) {}
}
