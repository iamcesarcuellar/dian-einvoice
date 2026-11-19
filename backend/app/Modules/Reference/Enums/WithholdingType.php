<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Withholding types (annex 1.9, table 13.2.2 of the toolbox). Tribute 06 is named ReteRenta in the
 * table and in the XML; the app calls it ReteFuente, its common name. Rates are stored as
 * percentages; ReteICA is expressed per thousand in the XML and the PDF (note *** of table 13.2.2).
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

    /** ReteICA rates are usually given per thousand: the XML and the PDF write them that way. */
    public function perThousand(): bool
    {
        return $this === self::ReteIca;
    }

    /**
     * ReteIVA is calculated on the IVA; ReteFuente and ReteICA on the subtotal before taxes.
     */
    public function base(): WithholdingBase
    {
        return $this === self::ReteIva ? WithholdingBase::Tax : WithholdingBase::Subtotal;
    }
}
