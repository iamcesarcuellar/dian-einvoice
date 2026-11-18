<?php

namespace App\Modules\Billing\Domain;

use Brick\Math\BigDecimal;

final readonly class Totals
{
    public function __construct(
        /** Sum of quantity times price, before discounts. */
        public BigDecimal $subtotalAmount,
        public BigDecimal $discountTotal,
        /** Sum of the line values. */
        public BigDecimal $lineExtensionAmount,
        /** Sum of the values of the lines that carry a tax, 0 % included. */
        public BigDecimal $taxExclusiveAmount,
        public BigDecimal $taxTotal,
        public BigDecimal $taxInclusiveAmount,
        public BigDecimal $payableAmount,
        public BigDecimal $withholdingTotal,
        /** Payable amount minus withholdings: what the customer actually pays. */
        public BigDecimal $amountDue,
    ) {}

    /**
     * @return array<string, string> Keyed like the document columns.
     */
    public function toArray(): array
    {
        return [
            'subtotal_amount' => (string) $this->subtotalAmount,
            'discount_total' => (string) $this->discountTotal,
            'line_extension_amount' => (string) $this->lineExtensionAmount,
            'tax_exclusive_amount' => (string) $this->taxExclusiveAmount,
            'tax_total' => (string) $this->taxTotal,
            'tax_inclusive_amount' => (string) $this->taxInclusiveAmount,
            'payable_amount' => (string) $this->payableAmount,
            'withholding_total' => (string) $this->withholdingTotal,
            'amount_due' => (string) $this->amountDue,
        ];
    }
}
