<?php

namespace App\Modules\Billing\Enums;

/**
 * Balance of an issued invoice after its notes, from the notes the local simulator accepted:
 * open while none credited it, partially_credited once one did, credited when less than one peso
 * is left to credit, and voided when a credit note with the void concept was accepted. A voided
 * invoice takes no more notes.
 */
enum BalanceStatus: string
{
    case Open = 'open';
    case PartiallyCredited = 'partially_credited';
    case Credited = 'credited';
    case Voided = 'voided';
}
