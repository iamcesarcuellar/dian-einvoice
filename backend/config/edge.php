<?php

// The edge in front of the functions (the content delivery network of the emulated cloud and of prod, Infrastructure
// task 4): every request reaches API Gateway through it, which refuses any other (the origin verifier). Locally there
// is no edge: the development server of the web app forwards /api and /sanctum to the API.
return [
    // BEHIND_EDGE of the functions. Behind the edge the application sees the host of API Gateway, which refuses
    // whoever does not come through the edge, so the URLs it generates (pagination links, the servers of the API
    // reference) take the public origin, APP_URL; and the address of the client is the one the edge saw.
    'behind' => (bool) env('BEHIND_EDGE', false),

    // How many entries at the end of X-Forwarded-For the known hops of the stage appended, the one of the edge
    // included: the address of the client is the first of them, and whatever the client wrote before it counts for
    // nothing. CloudFront appends the address of the connection of the client (as AWS documents), and in AWS API
    // Gateway appends that of the edge location after it: 2 in prod. The API Gateway of the emulator appends none:
    // 1 in the emulated cloud (EDGE_FORWARDED_HOPS of the functions, infra/functions/serverless.yml).
    'forwarded_hops' => (int) env('EDGE_FORWARDED_HOPS', 2),
];
