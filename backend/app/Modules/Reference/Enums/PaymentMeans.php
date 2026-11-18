<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Payment means (DIAN technical annex 1.9).
 */
enum PaymentMeans: string
{
    use CodeList;

    case Cash = '10';
    case Check = '20';
    case BankDeposit = '42';
    case BankTransfer = '47';
    case CreditCard = '48';
    case DebitCard = '49';
    case Other = 'ZZZ';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Check => 'Check',
            self::BankDeposit => 'Bank deposit',
            self::BankTransfer => 'Bank transfer',
            self::CreditCard => 'Credit card',
            self::DebitCard => 'Debit card',
            self::Other => 'Other',
        };
    }
}
