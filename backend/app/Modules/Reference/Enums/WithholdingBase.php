<?php

namespace App\Modules\Reference\Enums;

/**
 * The amount a withholding is calculated on.
 */
enum WithholdingBase: string
{
    /** The IVA of the document. */
    case Tax = 'tax';

    /** The subtotal before taxes. */
    case Subtotal = 'subtotal';
}
