<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Fiscal responsibilities (DIAN technical annex 1.9).
 */
enum FiscalResponsibility: string
{
    use CodeList;

    case LargeTaxpayer = 'O-13';
    case SelfWithholder = 'O-15';
    case IvaWithholdingAgent = 'O-23';
    case SimpleTaxRegime = 'O-47';
    case NotApplicable = 'R-99-PN';

    public function label(): string
    {
        return match ($this) {
            self::LargeTaxpayer => 'Large taxpayer',
            self::SelfWithholder => 'Self-withholder',
            self::IvaWithholdingAgent => 'IVA withholding agent',
            self::SimpleTaxRegime => 'Simple tax regime',
            self::NotApplicable => 'Not applicable',
        };
    }
}
