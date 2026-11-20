<?php

// Merged with the framework defaults: only the stores added here are listed. Locally the cache, the
// sessions and the rate limits live in Redis (CACHE_STORE=redis, SESSION_DRIVER=redis). In the emulated
// cloud each one has a table of DynamoDB of its own, with automatic expiry (TTL on expires_at): the cache
// store dynamodb, the sessions (SESSION_DRIVER=dynamodb, SESSION_STORE=dynamodb-sessions) and the rate
// limiters (CACHE_LIMITER=dynamodb-limits). Their clients take the shared AWS settings of config/aws.php
// (AppServiceProvider).
return [

    'stores' => [

        'dynamodb' => [
            'driver' => 'dynamodb',
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
        ],

        'dynamodb-sessions' => [
            'driver' => 'dynamodb',
            'table' => env('DYNAMODB_SESSIONS_TABLE', 'sessions'),
        ],

        'dynamodb-limits' => [
            'driver' => 'dynamodb',
            'table' => env('DYNAMODB_LIMITS_TABLE', 'limits'),
        ],

    ],

    // The store of the rate limiters (sign-in, sign-up, search...); the default store when unset.
    'limiter' => env('CACHE_LIMITER'),

];
