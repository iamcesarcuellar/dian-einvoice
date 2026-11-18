<?php

namespace App\Modules\Payments\Ports;

/**
 * The numbering of the payment receipts, which lives with the other numbering ranges (Settings →
 * Numbering, type payment). next() locks the range inside the transaction of the caller and takes
 * its next number, so a payment that fails rolls it back: no gaps and no repeats.
 */
interface ReceiptNumbering
{
    public function next(): ReceiptNumber;
}
