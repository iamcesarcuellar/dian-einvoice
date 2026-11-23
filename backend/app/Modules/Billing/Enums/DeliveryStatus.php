<?php

namespace App\Modules\Billing\Enums;

/**
 * The delivery of an accepted document to its customer, apart from its electronic status. It starts
 * when the local DIAN simulator accepts the document, and runs three queued steps (DeliveryStep)
 * while pending: attached_document, pdf and email. Then it is delivered, or not_applicable when the
 * customer has no email. A step that fails leads to failed; Send again, or Regenerate PDF when the
 * PDF failed, takes it back to pending, and so does Send again after delivered or not_applicable.
 * In this project the email always goes to the local development mailbox, so delivered means
 * "the local mailbox accepted it", and nothing leaves the machine. Drafts and documents not
 * accepted yet have no delivery status.
 */
enum DeliveryStatus: string
{
    /** Building the AttachedDocument or the PDF, or packaging and sending the email. */
    case Pending = 'pending';

    /** The mail server accepted the email with the zip. */
    case Delivered = 'delivered';

    /** A step failed, or every email attempt did; the reason is kept with the document. */
    case Failed = 'failed';

    /** The customer has no email, such as the final consumer: the files exist, nothing is sent. */
    case NotApplicable = 'not_applicable';

    /** The delivery has ended: nothing moves it until someone acts on it. */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
