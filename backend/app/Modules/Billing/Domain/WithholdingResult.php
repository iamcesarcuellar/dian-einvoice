<?php

namespace App\Modules\Billing\Domain;

use Brick\Math\BigDecimal;

final readonly class WithholdingResult
{
    public function __construct(
        public BigDecimal $baseAmount,
        public BigDecimal $amount,
    ) {}
}
