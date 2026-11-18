<?php

namespace App\Modules\Billing\Domain;

use Brick\Math\BigDecimal;

final readonly class LineResult
{
    public function __construct(
        /** Quantity times unit price. */
        public BigDecimal $baseAmount,
        public BigDecimal $discountAmount,
        /** Base minus discount: the value of the line before taxes. */
        public BigDecimal $lineExtensionAmount,
        public BigDecimal $taxAmount,
    ) {}
}
