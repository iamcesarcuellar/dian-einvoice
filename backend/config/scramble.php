<?php

// Package defaults apply to every option not listed here.
return [

    // Document the routes under /api/v1; the docs show paths relative to it.
    'api_path' => 'api/v1',

    'info' => [
        'version' => '0.1.0',
        'description' => 'API of dian-einvoice. The web app signs in with a session cookie: call GET /sanctum/csrf-cookie first and send the XSRF-TOKEN cookie back in the X-XSRF-TOKEN header. Errors use application/problem+json. Tenant endpoints, from stage 2 on, act on the company sent in the X-Company-Id header.',
    ],

];
