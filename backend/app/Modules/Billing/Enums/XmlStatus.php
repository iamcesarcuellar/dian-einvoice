<?php

namespace App\Modules\Billing\Enums;

/**
 * State of the electronic document (the UBL XML) of an issued document. Drafts have none.
 */
enum XmlStatus: string
{
    /** Issued; the XML is generated and signed right after the issue is committed. */
    case Pending = 'pending';

    /** Generated but not signed yet. Generation and signing run together for now, so it is transient. */
    case Generated = 'generated';

    /** Signed with the company certificate and stored, with its path, SHA-256, size and signing data. */
    case Signed = 'signed';

    /** Generation or signing failed; the reason is in the timeline and it can be regenerated. */
    case Failed = 'failed';

    /** Whether a stored XML can be downloaded. */
    public function available(): bool
    {
        return $this === self::Signed || $this === self::Generated;
    }
}
