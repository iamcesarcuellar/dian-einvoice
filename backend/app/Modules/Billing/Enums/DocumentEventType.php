<?php

namespace App\Modules\Billing\Enums;

/**
 * Entries of a document timeline.
 */
enum DocumentEventType: string
{
    case Created = 'created';
    case Issued = 'issued';
}
