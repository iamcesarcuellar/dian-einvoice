<?php

use App\Modules\Identity\Models\User;

// Framework defaults apply to every option not listed here.
return [

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

];
