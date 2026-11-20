<?php

// Cross-origin requests (Laravel's HandleCors). The web app calls the API on its own origin, through the
// development server of the local stack or behind the edge, so it needs none: the CSRF cookie of Sanctum is not
// offered to other origins. The API under /api/v1 answers any origin without credentials, so a page of another
// origin can call it with an API key but never with the session of the web app, which also works only from the
// stateful origins (SANCTUM_STATEFUL_DOMAINS). Framework defaults apply to every option not listed here.
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_headers' => ['*'],
    'supports_credentials' => false,
];
