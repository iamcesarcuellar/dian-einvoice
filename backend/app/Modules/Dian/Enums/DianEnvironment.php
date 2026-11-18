<?php

namespace App\Modules\Dian\Enums;

/**
 * DIAN destination environment (annex 1.9, table 13.1.1): cbc:ProfileExecutionID, the
 * cbc:UUID@schemeID and the last part of the CUFE. This project only uses 2, the habilitación
 * (test) environment; production (1) is out of its scope and deliberately has no case here.
 */
enum DianEnvironment: int
{
    case Test = 2;

    /** Base of the verification URL drawn as the QR code (annex 1.9, section 11.7.1). */
    public function qrBaseUrl(): string
    {
        return match ($this) {
            self::Test => 'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey=',
        };
    }

    public function qrUrl(string $cufe): string
    {
        return $this->qrBaseUrl().$cufe;
    }

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Test (habilitación)',
        };
    }
}
