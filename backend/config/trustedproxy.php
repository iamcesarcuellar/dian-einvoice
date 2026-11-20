<?php

// The proxies whose X-Forwarded-* headers the application believes (Laravel's TrustProxies middleware).
// Locally none: the web app's development server is the only client of the API. In AWS Lambda, PHP-FPM
// receives every request from the Bref runtime with REMOTE_ADDR 127.0.0.1, and the address API Gateway saw
// comes last in X-Forwarded-For: trusting 127.0.0.1 alone (TRUSTED_PROXIES of the functions) makes the
// request IP that last address, so a client cannot choose its own address for the rate limits by sending
// the header itself. Bref's bridge would otherwise trust every address, and the first one of the header
// is the client's to write. Behind the edge (the functions, config/edge.php), the application first sets that
// forwarded address to the entry the edge appended: the last one, once an entry API Gateway appended for its peer
// is dropped (App\Http\Middleware\EdgeClientAddress), so neither the address API Gateway saw, which is the edge's,
// nor anything the client wrote counts for a client.
return [

    'proxies' => env('TRUSTED_PROXIES'),

];
