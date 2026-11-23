<?php

namespace App\Modules\Dian\Transmission;

/**
 * How one call to the DIAN web service (the local simulator) ended.
 */
enum TransmissionOutcome: string
{
    /** SendBillSync responded with the validation result. */
    case Responded = 'responded';

    /** GetStatus found the document already processed: its result is taken as valid. */
    case Found = 'found';

    /** GetStatus does not know the document: it never arrived, so it is sent. */
    case NotFound = 'not_found';

    /** No response in time after the request went out: it may have arrived. */
    case Timeout = 'timeout';

    /** The connection closed without a response after the request went out: it may have arrived. */
    case NoResponse = 'no_response';

    /** The connection could not be opened: nothing was sent. */
    case Refused = 'refused';

    /** HTTP 5xx: the service failed before processing the request. */
    case ServerError = 'server_error';

    /** HTTP 200 with a body that is not the service's response. */
    case InvalidResponse = 'invalid_response';

    /** HTTP 4xx: the request itself is wrong, so trying again would not help. */
    case ClientError = 'client_error';

    /** The worker stopped during the call, and no response was recorded. */
    case Abandoned = 'abandoned';

    /** Worth another attempt: the service did not give a validation result. */
    public function isTransient(): bool
    {
        return match ($this) {
            self::Timeout, self::NoResponse, self::Refused, self::ServerError, self::InvalidResponse, self::Abandoned => true,
            default => false,
        };
    }
}
