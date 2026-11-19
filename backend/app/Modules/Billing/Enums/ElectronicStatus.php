<?php

namespace App\Modules\Billing\Enums;

/**
 * State machine of the electronic document of an issued invoice. Each step of the chain runs as a
 * queued job that locks the document, checks it is in the state the step expects and moves it on:
 *
 *   pending → generated → signed → sending → accepted
 *                                          ↘ rejected → pending (Resend)
 *   any step ↘ failed → pending | generated | signed (Retry now, from the step that failed)
 *
 * While sending, a transmission that failed for a transient reason waits for its next attempt;
 * the attempt number and the time of the next one are kept on the document. Drafts have no state.
 * In this project the DIAN is always the local simulator, so accepted and rejected mean "by the
 * local simulator", never by the DIAN.
 */
enum ElectronicStatus: string
{
    /** Issued; the XML is about to be generated. */
    case Pending = 'pending';

    /** UBL XML built and stored, not signed yet. */
    case Generated = 'generated';

    /** Signed with the company certificate and stored; about to be sent. */
    case Signed = 'signed';

    /** Being sent, or waiting for the next attempt after a transient failure. */
    case Sending = 'sending';

    /** Accepted by the local DIAN simulator, possibly with notifications. */
    case Accepted = 'accepted';

    /** Rejected by the rules of the local DIAN simulator; it can be sent again with Resend. */
    case Rejected = 'rejected';

    /** A step failed, or every transmission attempt did; Retry now starts again from that step. */
    case Failed = 'failed';

    /** The chain has ended: nothing moves the document until someone acts on it. */
    public function isFinal(): bool
    {
        return $this === self::Accepted || $this === self::Rejected || $this === self::Failed;
    }

    /**
     * States this one may move to.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Generated, self::Failed],
            self::Generated => [self::Signed, self::Failed],
            self::Signed => [self::Sending, self::Failed],
            self::Sending => [self::Accepted, self::Rejected, self::Failed],
            self::Rejected => [self::Pending],
            self::Failed => [self::Pending, self::Generated, self::Signed],
            self::Accepted => [],
        };
    }

    public function canMoveTo(self $state): bool
    {
        return in_array($state, $this->next(), true);
    }

    /**
     * States of a chain still running, for the "processing" filter.
     *
     * @return list<self>
     */
    public static function processing(): array
    {
        return [self::Pending, self::Generated, self::Signed, self::Sending];
    }
}
