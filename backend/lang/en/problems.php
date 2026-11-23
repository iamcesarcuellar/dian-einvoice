<?php

// The fixed texts of the error responses (application/problem+json, App\Http\ProblemDetails): the title of each
// status, as HTTP names it, and the details that do not depend on the request.
return [

    'titles' => [
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '405' => 'Method Not Allowed',
        '406' => 'Not Acceptable',
        '409' => 'Conflict',
        '410' => 'Gone',
        '413' => 'Content Too Large',
        '415' => 'Unsupported Media Type',
        '419' => 'CSRF Token Mismatch',
        '422' => 'Unprocessable Content',
        '423' => 'Locked',
        '429' => 'Too Many Requests',
        '500' => 'Internal Server Error',
        '502' => 'Bad Gateway',
        '503' => 'Service Unavailable',
        '504' => 'Gateway Timeout',
    ],

    'invalid' => 'The given data was invalid.',
    'unauthenticated' => 'Sign in to the web app, or send an API key in the Authorization header: Bearer dei_...',
    'not_found' => 'The requested resource was not found.',
    'csrf' => 'The CSRF token is missing or has expired. Reload the page and try again.',
    'server_error' => 'Something went wrong on our side.',
    'unhealthy' => 'A service the API needs is not reachable.',

    // Errors the framework raises with a text of its own, which stays as it is in English.
    'framework' => [
        '405' => 'This method is not allowed on this path.',
        '413' => 'The request is too large.',
        '429' => 'Too many requests. Wait a moment and try again.',
    ],

];
