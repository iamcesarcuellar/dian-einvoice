<?php

namespace App\Modules\Developers\Enums;

/**
 * A delivery is pending while attempts remain, then succeeded (a 2xx answer) or failed (attempts
 * exhausted, or the subscription was switched off). A failed delivery can be sent again by hand.
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
