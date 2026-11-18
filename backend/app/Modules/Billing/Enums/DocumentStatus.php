<?php

namespace App\Modules\Billing\Enums;

enum DocumentStatus: string
{
    /** Editable; it has no number yet. */
    case Draft = 'draft';

    /** Numbered and final: it can no longer be edited or deleted. */
    case Issued = 'issued';
}
