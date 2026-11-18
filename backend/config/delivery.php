<?php

// Delivery of accepted documents to the customer: AttachedDocument, PDF and the email with both in a zip.
return [

    // The local development mailbox (compose service mail, Mailpit), a development tool: the web
    // interface people open, and the API the backend reads (make seed clears it, delivery:check reads it).
    'mailbox' => [
        'url' => env('LOCAL_MAILBOX_URL', 'http://localhost:8603'),
        'api_url' => env('LOCAL_MAILBOX_API_URL', 'http://mail:8025'),
        'timeout' => (float) env('LOCAL_MAILBOX_TIMEOUT', 3),
    ],

    // Largest zip one email may carry (annex 1.9, section 9.1: 2 MB per delivery).
    'max_package_bytes' => 2 * 1024 * 1024,

];
