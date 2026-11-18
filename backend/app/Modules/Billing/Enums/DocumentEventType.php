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
}
