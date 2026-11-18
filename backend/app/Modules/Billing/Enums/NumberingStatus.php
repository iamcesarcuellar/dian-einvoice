<?php

namespace App\Modules\Billing\Enums;

/**
 * Computed state of a numbering range.
 */
enum NumberingStatus: string
{
    case Active = 'active';

    /** Its validity has not started yet. */
    case Upcoming = 'upcoming';

    /** Its validity has ended. */
    case Expired = 'expired';

    /** Every number of the range has been used. */
    case Exhausted = 'exhausted';
}
