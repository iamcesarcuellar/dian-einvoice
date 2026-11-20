<?php

// Which adapter serves each port of the application. The default local stack (make up, make demo) uses
// the local ones; the emulated cloud (make cloud-up) and production use the AWS ones, with the resources
// of config/aws.php. Cache, sessions, the queue and email are chosen by Laravel's own settings
// (CACHE_STORE, CACHE_LIMITER, SESSION_DRIVER, QUEUE_CONNECTION, MAIL_MAILER), and the secrets by
// APP_SECRETS, read before the configuration (App\Support\Secrets\LoadSecrets).
return [

    // local: the private disk storage/app/documents. s3: the documents bucket.
    'document_storage' => env('DOCUMENT_STORAGE', 'local'),

    // local: rows encrypted with APP_KEY. kms: each bundle encrypted with a data key of its own that the
    // signing key of KMS protects (envelope encryption).
    'key_store' => env('KEY_STORE', 'local'),

    // queue: the outbox relay dispatches the jobs of each event to the queue (Redis). sns: it publishes
    // each event to the topic of document events, whose subscriptions route it to the SQS queues.
    'outbox_publisher' => env('OUTBOX_PUBLISHER', 'queue'),

    // redis: the rate limits of API keys count in Redis. cache: in the store of the rate limiters
    // (cache.limiter), the limits table of DynamoDB in the emulated cloud.
    'api_key_limits' => env('API_KEY_LIMITS', 'redis'),

];
