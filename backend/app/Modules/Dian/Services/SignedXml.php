<?php

namespace App\Modules\Dian\Services;

use App\Modules\Dian\Models\SigningCertificate;
use App\Support\Storage\StoredDocument;
use Illuminate\Support\Carbon;

/**
 * A signed XML as stored, with its xades:SigningTime and the certificate that signed it.
 */
final readonly class SignedXml
{
    public function __construct(
        public StoredDocument $stored,
        public Carbon $signedAt,
        public SigningCertificate $certificate,
    ) {}
}
