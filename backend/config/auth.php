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

    // A failed sign-in takes at least this long, whether or not the email has an account (LoginRequest).
    'login_timebox_microseconds' => (int) env('LOGIN_TIMEBOX_MICROSECONDS', 400_000),

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

];
