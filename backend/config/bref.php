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

    // The bridge would log "Processing job" and "Processed job" lines of its own for every job; the application
    // writes one line per job in the schema of its logs instead (queue.job, docs/observability.md).
    'log_jobs' => false,

];
