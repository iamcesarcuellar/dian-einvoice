<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Payment forms (DIAN technical annex 1.9).
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
