<?php

// The AWS services of the emulated cloud (compose profile cloud, where the local emulator Floci serves them) and
// of production: the client settings every AWS adapter shares, and the resources of the CDK app (infra/cdk).
// make cloud-up writes these values to backend/.env.cloud from the outputs of the local CDK stage; the
// default local stack leaves them unset and uses none of them.
return [

    // Region of the stage; the local stage uses the default region of the emulator.
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    // The local emulator (http://floci:4566 on the internal network of compose). Unset in AWS, where each
    // client finds the regional endpoint of its service.
    'endpoint' => env('AWS_ENDPOINT_URL'),

    // Buckets in the path of the URL instead of the host name: the emulator serves every bucket under its
    // own host. AWS uses host names.
    'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', false),

    // The emulator takes any credentials. In AWS they come from the function's role (the stack compute)
    // through the default provider chain, so these stay unset.
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'token' => env('AWS_SESSION_TOKEN'),

    // Seconds to open a connection, and to wait for one call's whole response; the SDK retries
    // throttling and transient errors up to 'attempts' times in all.
    'connect_timeout' => (float) env('AWS_CONNECT_TIMEOUT', 2),
    'timeout' => (float) env('AWS_TIMEOUT', 10),
    'attempts' => (int) env('AWS_MAX_ATTEMPTS', 3),

    // Resources of the CDK stage. The queues themselves are in config/queue.php (connection sqs); their
    // dead-letter queues and the secret are read by make cloud-check only.
    'documents_bucket' => env('DOCUMENTS_BUCKET'),
    'signing_key' => env('SIGNING_KEY_ID'),
    'events_topic' => env('EVENTS_TOPIC_ARN'),
    'dead_letter_queues' => [
        'chain' => env('SQS_CHAIN_DLQ_URL'),
        'webhooks' => env('SQS_WEBHOOKS_DLQ_URL'),
    ],
    'app_secret' => env('APP_SECRETS_ID'),

];
