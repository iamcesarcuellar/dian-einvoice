<?php

namespace App\Modules\Billing\Enums;

/**
 * The three steps of the customer delivery, in order. Each one runs as one queued job.
 */
enum DeliveryStep: string
{
    /** Build the AttachedDocument (signed invoice and ApplicationResponse) and sign it. */
    case AttachedDocument = 'attached_document';

    /** Draw the PDF, the graphic representation of the invoice, from its frozen copies. */
    case Pdf = 'pdf';

    /** Package the AttachedDocument and the PDF in a zip and email it to the customer. */
    case Email = 'email';
}
