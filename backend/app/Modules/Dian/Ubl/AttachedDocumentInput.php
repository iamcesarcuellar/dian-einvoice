<?php

namespace App\Modules\Dian\Ubl;

use DateTimeInterface;

/**
 * What an AttachedDocument carries besides the frozen copies of the document: its own consecutive,
 * the moment it is generated, the two XML files it contains and who validated the document.
 */
final readonly class AttachedDocumentInput
{
    public function __construct(
        /** Own consecutive of the generator (AE04b). */
        public string $id,
        /** Not earlier than any document inside it (AE05). */
        public DateTimeInterface $issuedAt,
        /** The signed invoice, as stored. */
        public string $invoiceXml,
        /** The ApplicationResponse of the validation, as stored. */
        public string $responseXml,
        public ApplicationResponseSummary $response,
        /** cbc:ValidatorID (AE52). */
        public string $validator,
    ) {}
}
