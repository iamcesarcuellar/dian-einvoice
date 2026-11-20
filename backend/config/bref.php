<?php

// Bref's Laravel bridge (bref/laravel-bridge), which the functions of the emulated cloud and of production
// load (App\Providers\AppServiceProvider registers it only inside AWS Lambda). Merged over the defaults
// of the package; only what this application changes is here.
return [

    // No public file is served by the functions: the web app is a separate application.
    'assets' => [],

    // The route of presigned uploads stays off: the application takes its uploads (signing certificates)
    // through its own API, within the request size of Lambda.
    'uploads' => [
        'route' => null,
    ],

    // One log line per job processed, processed or failed, in the logs of the consumers.
    'log_jobs' => true,

];
