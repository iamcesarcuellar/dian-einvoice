<?php

use App\Modules\Identity\Models\User;

// Framework defaults apply to every option not listed here.
return [

    // web: the session of the web app (Sanctum). api-key: Authorization: Bearer with an API key of a
    // company (module Developers); tenant routes accept either, as auth:api-key,sanctum.
    'guards' => [
        'api-key' => [
            'driver' => 'api-key',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

];
