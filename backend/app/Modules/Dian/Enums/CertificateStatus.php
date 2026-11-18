<?php

namespace App\Modules\Dian\Enums;

/**
 * State of a signing certificate on a given day.
 */
enum CertificateStatus: string
{
    case Valid = 'valid';

    /** Valid, but it expires in less than 30 days. */
    case ExpiringSoon = 'expiring_soon';

    case Expired = 'expired';

    case NotYetValid = 'not_yet_valid';

    /** Days before the end of the validity when a certificate starts expiring soon. */
    public const int WARNING_DAYS = 30;

    /** Whether documents can be signed with it now. */
    public function usable(): bool
    {
        return $this === self::Valid || $this === self::ExpiringSoon;
    }
}
