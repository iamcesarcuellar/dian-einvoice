<?php

namespace App\Modules\Payments\Ports;

use RuntimeException;

/** The amount to apply is above what is left to collect on the invoice. */
final class AmountExceedsBalance extends RuntimeException
{
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $balance,
        public readonly string $amount,
    ) {
        parent::__construct(sprintf('Invoice %s has %s left to collect; %s cannot be applied to it.', $invoiceNumber, $balance, $amount));
    }
}
