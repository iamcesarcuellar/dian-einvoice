<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Withholding types (DIAN technical annex 1.9).
 */
enum WithholdingType: string
{
    use CodeList;

    case ReteIva = '05';
    case ReteFuente = '06';
    case ReteIca = '07';

    public function label(): string
    {
        return match ($this) {
            self::ReteIva => 'ReteIVA',
            self::ReteFuente => 'ReteFuente',
            self::ReteIca => 'ReteICA',
        };
    }

    /**
     * ReteIVA is calculated on the IVA; ReteFuente and ReteICA on the subtotal before taxes.
     */
    public function base(): WithholdingBase
    {
        return $this === self::ReteIva ? WithholdingBase::Tax : WithholdingBase::Subtotal;
    }
}
