<?php

// Transmission of electronic documents. In this project the DIAN is always the local simulator
// (compose service simulator): the SOAP URL points to it and the real web service is never called.
return [

    // SOAP 1.2 endpoint with the operations SendBillSync and GetStatus (annex 1.9, section 7). The
    // habilitación service of the DIAN is https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc
    // and also requires a WS-Security signature of the SOAP header, which is not built yet.
    'soap_url' => env('DIAN_SOAP_URL', 'http://simulator:8602/WcfDianCustomerServices.svc'),

    // Seconds to open the connection, and to wait for the whole answer.
    'connect_timeout' => (float) env('DIAN_CONNECT_TIMEOUT', 2),
    'timeout' => (float) env('DIAN_TIMEOUT', 8),

    // Seconds to wait before each new attempt after a transient failure (no answer, an HTTP 5xx or a
    // refused connection): one first attempt plus one per delay. Each wait grows by a random share
    // of up to retry_jitter of itself, so documents that failed together do not retry together.
    'retry_delays' => array_values(array_filter(
        array_map('intval', explode(',', (string) env('DIAN_RETRY_DELAYS', '2,5,15,45,120'))),
        fn (int $seconds): bool => $seconds > 0,
    )),
    'retry_jitter' => (float) env('DIAN_RETRY_JITTER', 0.2),

    // Admin API of the local simulator: taxpayer registration, failure modes and recent activity.
    'simulator' => [
        'url' => env('DIAN_SIMULATOR_URL', 'http://simulator:8602'),
        // Configuration only, never a default in the code: make up writes a random one to backend/.env
        // and compose hands the same one to the simulator.
        'token' => (string) env('DIAN_SIMULATOR_TOKEN', ''),
        'timeout' => (float) env('DIAN_SIMULATOR_TIMEOUT', 3),
    ],

];
