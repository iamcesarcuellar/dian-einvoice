<?php

namespace App\Modules\Payments\Ports;

use RuntimeException;

/** The invoice is not an accepted invoice of the customer of the payment. */
final class InvoiceNotPayable extends RuntimeException {}
