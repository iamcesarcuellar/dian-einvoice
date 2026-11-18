<?php

namespace App\Modules\Billing\Domain;

/**
 * Everything calculated for a document. Lines and withholdings keep the order they came in.
 */
final readonly class Calculation
{
    /**
     * @param  list<LineResult>  $lines
     * @param  list<TaxResult>  $taxes
     * @param  list<WithholdingResult>  $withholdings
     */
    public function __construct(
        public array $lines,
        public array $taxes,
        public array $withholdings,
        public Totals $totals,
    ) {}
}
