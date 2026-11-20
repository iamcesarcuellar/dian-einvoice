<?php

// The edge in front of the functions (the content delivery network of the emulated cloud and of prod, Infrastructure
// task 4): every request reaches API Gateway through it, which refuses any other (the origin verifier). Locally there
// is no edge: the development server of the web app forwards /api and /sanctum to the API.
return [
    // BEHIND_EDGE of the functions. Behind the edge the application sees the host of API Gateway, which refuses
    // whoever does not come through the edge, so the URLs it generates (pagination links, the servers of the API
    // reference) take the public origin, APP_URL; and the address of the client is the one the edge saw: the last
    // entry of X-Forwarded-For once the one API Gateway may append, the address of its peer, is dropped
    // (App\Http\Middleware\EdgeClientAddress). No count of hops: the same rule holds whether API Gateway appends its
    // entry, as AWS documents, or not, as the API Gateway of the emulator.
    'behind' => (bool) env('BEHIND_EDGE', false),
];
