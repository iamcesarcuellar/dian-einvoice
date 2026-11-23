<?php

// The names of the fields of each request, as validation messages write them. A null name keeps the framework's: the
// field's key, its underscores as spaces.
return [

    'attributes' => [
        'key' => [
            'name' => null,
            'level' => null,
            'expires_at' => 'expiration date',
            'rate_limit_per_minute' => 'requests per minute',
        ],
        'subscription' => [
            'url' => null,
            'events' => null,
            'events.*' => null,
            'description' => null,
            'is_active' => null,
        ],
        'receiver' => [
            'mode' => null,
            'count' => null,
            'delay_ms' => null,
        ],
    ],

];
