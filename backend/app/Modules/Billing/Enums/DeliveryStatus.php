<?php

namespace App\Modules\Billing\Enums;

/**
 * Delivery of an accepted document to its customer, apart from its electronic status. It starts
 * when the local DIAN simulator accepts the document and runs three queued steps (DeliveryStep):
 *
 *   pending (attached_document → pdf → email) → delivered | not_applicable
 *   any step ↘ failed → pending (Send again, or Regenerate PDF when the PDF failed)
 *   delivered | not_applicable → pending (Send again)
 *
 * The email always goes to the local development mailbox in this project: delivered means "the
 * local mailbox accepted it", and nothing leaves the machine. Drafts and documents not accepted yet
 * have no delivery status.
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
