<?php

// The proxies whose X-Forwarded-* headers the application believes (Laravel's TrustProxies middleware).
// Locally none: the web app's development server is the only client of the API. In AWS Lambda, PHP-FPM
// receives every request from the Bref runtime with REMOTE_ADDR 127.0.0.1, and the address API Gateway saw
// comes last in X-Forwarded-For: trusting 127.0.0.1 alone (TRUSTED_PROXIES of the functions) makes the
// request IP that last address, so a client cannot choose its own address for the rate limits by sending
// the header itself. Bref's bridge would otherwise trust every address, and the first one of the header
// is the client's to write. Infrastructure task 4 adds the hop of the content delivery network in front.
return [

    'proxies' => env('TRUSTED_PROXIES'),

];
