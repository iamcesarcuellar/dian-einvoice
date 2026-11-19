<?php

// Framework defaults apply to every option not listed here: the driver (SESSION_DRIVER, Redis), the
// idle lifetime (SESSION_LIFETIME, 120 minutes) and a host-only cookie (SESSION_DOMAIN unset).
return [

    // The session cookie, and the XSRF-TOKEN cookie Sanctum sets with it, travel only over HTTPS in
    // production, unless SESSION_SECURE_COOKIE says otherwise; locally the web app runs on HTTP.
    'secure' => (bool) env('SESSION_SECURE_COOKIE', env('APP_ENV', 'production') === 'production'),

    // Never readable from JavaScript, and left out of cross-site requests other than a navigation.
    'http_only' => true,
    'same_site' => 'lax',

];
