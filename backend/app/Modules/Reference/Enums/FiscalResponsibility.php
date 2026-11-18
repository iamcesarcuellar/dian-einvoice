<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Fiscal responsibilities (DIAN technical annex 1.9, table 13.2.6.1). English names of the official
 * ones: O-48 is "Impuesto sobre las ventas" and O-49 "No responsable de IVA".
 */
enum FiscalResponsibility: string
{
    use CodeList;

    case LargeTaxpayer = 'O-13';
    case SelfWithholder = 'O-15';
    case IvaWithholdingAgent = 'O-23';
    case SimpleTaxRegime = 'O-47';
    case SalesTax = 'O-48';
    case NotIvaResponsible = 'O-49';
    case NotApplicable = 'R-99-PN';

    public function label(): string
    {
        return match ($this) {
            self::LargeTaxpayer => 'Large taxpayer',
            self::SelfWithholder => 'Self-withholder',
            self::IvaWithholdingAgent => 'IVA withholding agent',
            self::SimpleTaxRegime => 'Simple tax regime',
            self::SalesTax => 'Sales tax (IVA)',
            self::NotIvaResponsible => 'Not responsible for IVA',
            self::NotApplicable => 'Not applicable',
        };
    }
}
