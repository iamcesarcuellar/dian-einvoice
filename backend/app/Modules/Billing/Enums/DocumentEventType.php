<?php

namespace App\Modules\Billing\Enums;

/**
 * Entries of a document timeline. Those of the transmission always refer to the local DIAN
 * simulator, which is the only DIAN this project talks to.
 */
enum DocumentEventType: string
{
    case Created = 'created';
    case Issued = 'issued';
    case XmlGenerated = 'xml_generated';
    case XmlSigned = 'xml_signed';

    /**
     * FAD09e, CAD09e and DAD09e: the chain generated or signed the XML on a later day in Colombia,
     * so the issue date and time moved to that moment, the due date by the same days, and the CUFE
     * or CUDE was calculated again. Payload: run, step, previous_issue_date, previous_issue_time,
     * previous_due_date, previous_key, issue_date, issue_time, due_date and key (the due date of a
     * cash document is its issue date).
     */
    case IssueDateMoved = 'issue_date_moved';

    /** Generation or signing failed; payload: step and reason. */
    case XmlFailed = 'xml_failed';

    /**
     * One call of an attempt: SendBillSync, or GetStatus when an earlier send may have arrived.
     * Payload: run, attempt, operation, outcome, duration_ms, http_status, status_code, error, and
     * retry_in and next_attempt_at when a retry was scheduled.
     */
    case TransmissionAttempt = 'transmission_attempt';

    /** Accepted by the local simulator; payload: status_code and notifications. */
    case Accepted = 'accepted';

    /** Rejected by the rules of the local simulator; payload: status_code and rules. */
    case Rejected = 'rejected';

    /** Every transmission attempt failed; payload: attempts and reason. */
    case TransmissionFailed = 'transmission_failed';

    /** Retry now, by a user; payload: step and whether it was waiting or failed. */
    case RetryRequested = 'retry_requested';

    /** Resend after a rejection, by a user; payload: whether the CUFE changed. */
    case ResendRequested = 'resend_requested';

    /** The AttachedDocument was built and signed; payload: run, file_name, sha256 and size. */
    case AttachedDocumentBuilt = 'attached_document_built';

    /** The PDF was generated; payload: run, file_name, pages, sha256 and size. */
    case PdfGenerated = 'pdf_generated';

    /**
     * One email attempt to the customer, always caught by the local mailbox. Payload: run, attempt,
     * max_attempts, recipient, outcome (sent or failed), error, message_id, package, and retry_in and
     * next_attempt_at when a retry was scheduled.
     */
    case EmailAttempt = 'email_attempt';

    /** The customer has no email address, so nothing was sent; payload: run and package. */
    case DeliveryNotApplicable = 'delivery_not_applicable';

    /** A delivery step failed, or every email attempt did; payload: step, reason and attempts. */
    case DeliveryFailed = 'delivery_failed';

    /** Send again, by a user; payload: run, recipient, previous_recipient and the step it starts from. */
    case DeliveryRequested = 'delivery_requested';

    /** Regenerate the PDF after it failed, by a user; payload: run and the step it starts from. */
    case PdfRegenerationRequested = 'pdf_regeneration_requested';

    /**
     * On an invoice: the local simulator accepted one of its credit or debit notes. Payload: note_id,
     * note_type, number, amount, voided and the balance_status it left.
     */
    case NoteAccepted = 'note_accepted';

    /** On an invoice: a payment was applied to it. Payload: payment_id, number, amount and balance. */
    case PaymentApplied = 'payment_applied';

    /** On an invoice: a voided payment gave its amount back. Payload: payment_id, number, amount and balance. */
    case PaymentReleased = 'payment_released';
}
