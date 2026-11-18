<?php

namespace App\Modules\Billing\Enums;

/**
 * State of the UBL XML of an issued document. Drafts have none.
 */
enum XmlStatus: string
{
    /** Issued; the XML is generated right after the issue is committed. */
    case Pending = 'pending';

    /** Stored, with its path, SHA-256, size and date on the document. */
    case Generated = 'generated';

    /** Generation failed; the reason is in the timeline and it can be regenerated. */
    case Failed = 'failed';
}
