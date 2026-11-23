<?php

// The Spanish names of the fields of each request, as validation messages write them.

return [

    'attributes' => [
        'key' => [
            'name' => 'nombre',
            'level' => 'nivel',
            'expires_at' => 'fecha de vencimiento',
            'rate_limit_per_minute' => 'peticiones por minuto',
        ],
        'subscription' => [
            'url' => 'URL',
            'events' => 'eventos',
            'events.*' => 'evento',
            'description' => 'descripción',
            'is_active' => 'activa',
        ],
        'receiver' => [
            'mode' => 'modo',
            'count' => 'número de entregas',
            'delay_ms' => 'demora',
        ],
    ],

];
