<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Payment means offered (annex 1.9, table 13.3.4.2 of the toolbox, cbc:PaymentMeansCode): a subset
 * of the table, with English names of the official ones.
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
            self::BankTransfer => 'Bank debit transfer',
            self::CreditCard => 'Credit card',
            self::DebitCard => 'Debit card',
            self::Other => 'Other',
        };
    }
}
