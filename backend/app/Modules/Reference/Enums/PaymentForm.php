<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Payment forms (annex 1.9, table 13.3.4.1 of the toolbox, cac:PaymentMeans/cbc:ID).
 */
enum PaymentForm: string
{
    use CodeList;

    case Cash = '1';
    case Credit = '2';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Credit => 'Credit',
        };
    }
}
