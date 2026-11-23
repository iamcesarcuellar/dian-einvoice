<?php

// The structured logs of the functions: one JSON object per line with the fields of
// resources/observability/log-schema.json (App\Support\Observability\JsonLogFormatter, LOG_STDERR_FORMATTER of
// the functions), from which the metric filters of the CDK app make the metrics of the application. The local
// stack keeps the line format of Laravel and logs neither of the lines below: nothing changes there.
return [
    // The schema of every line, the single source of truth (docs/observability.md).
    'schema' => resource_path('observability/log-schema.json'),

    // The function that writes: null takes it from the name Lambda gives the function (AWS_LAMBDA_FUNCTION_NAME
    // without dian-einvoice-<stage>-); outside Lambda, the process.
    'function' => env('LOG_FUNCTION'),

    // The stage of the platform (APP_STAGE of the functions); the environment of the application elsewhere.
    'stage' => env('APP_STAGE', env('APP_ENV', 'local')),

    // One line per request the API answers (http.request) and per job a consumer runs (queue.job): on in the
    // functions, where they feed the metrics and the saved queries.
    'requests' => (bool) env('LOG_REQUESTS', false),
    'jobs' => (bool) env('LOG_JOBS', false),
];
