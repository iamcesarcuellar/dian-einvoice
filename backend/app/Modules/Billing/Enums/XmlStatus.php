<?php

namespace App\Modules\Billing\Enums;

use App\Modules\Billing\Models\Document;

/**
 * The state of an issued document's UBL XML file, derived from its electronic status: the API
 * shows it next to the chain, so clients know whether the signed XML can be downloaded. Drafts
 * have none.
 */
enum XmlStatus: string
{
    /** The chain has not built the XML yet. */
    case Pending = 'pending';

    /** Built but not signed yet; not available for download. */
    case Generated = 'generated';

    /** Signed with the company certificate and stored. */
    case Signed = 'signed';

    /** Generation or signing failed; the reason is in the timeline. */
    case Failed = 'failed';

    public static function of(Document $document): ?self
    {
        if ($document->electronic_status === null) {
            return null;
        }

        if ($document->xml_path !== null && $document->electronic_status !== ElectronicStatus::Pending && $document->electronic_status !== ElectronicStatus::Generated) {
            return self::Signed;
        }

        return match ($document->electronic_status) {
            ElectronicStatus::Generated => self::Generated,
            ElectronicStatus::Failed => self::Failed,
            default => self::Pending,
        };
    }

    /** Whether the signed XML can be downloaded. */
    public function available(): bool
    {
        return $this === self::Signed;
    }
}
