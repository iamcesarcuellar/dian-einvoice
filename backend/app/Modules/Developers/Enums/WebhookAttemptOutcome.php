<?php

namespace App\Modules\Developers\Enums;

/** How one attempt of a delivery ended. Only succeeded is a 2xx answer. */
enum WebhookAttemptOutcome: string
{
    case Succeeded = 'succeeded';

    /** An answer outside 2xx, redirects included: they are not followed. */
    case HttpError = 'http_error';

    /** No complete answer within the timeout. */
    case Timeout = 'timeout';

    /** The connection could not be opened or broke. */
    case ConnectionError = 'connection_error';

    /** The URL points to a private network and those are not allowed. */
    case Blocked = 'blocked';

    /** The worker stopped during the call; no outcome was recorded. */
    case Abandoned = 'abandoned';
}
