<?php

namespace App\Modules\Payments\Enums;

/** A payment is recorded, or voided with a reason; it is never edited or deleted. */
enum PaymentStatus: string
{
    case Recorded = 'recorded';
    case Voided = 'voided';
}
