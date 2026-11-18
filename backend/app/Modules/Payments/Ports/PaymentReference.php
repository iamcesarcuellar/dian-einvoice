<?php

namespace App\Modules\Payments\Ports;

/** The payment an invoice records an application of: enough to show it on the invoice. */
final readonly class PaymentReference
{
    public function __construct(
        public string $id,
        public string $number,
        public string $date,
        public string $meansCode,
    ) {}
}
