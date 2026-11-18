<?php

// The public API: API keys, idempotency, rate limits and webhooks (module Developers).
return [

    'api_keys' => [
        // Every key starts with this prefix, so leaked keys are easy to recognize and scan for.
        'prefix' => 'dei_',

        // Requests per minute of a key without a limit of its own.
        'rate_limit_per_minute' => (int) env('API_KEY_RATE_LIMIT', 120),
    ],

    'idempotency' => [
        // How long the response of a request with an Idempotency-Key is kept.
        'ttl_hours' => 24,

        // A reservation still processing after this many seconds belongs to a request that died;
        // another request with the same key may take it over. Above the PHP time limit (30 s).
        'lease_seconds' => 60,
    ],

    'webhooks' => [
        // Seconds an attempt may take, connection included.
        'timeout' => (float) env('WEBHOOK_TIMEOUT', 5),

        // Waits before each new attempt after a failure: the same as the rest of the chain
        // (DIAN_RETRY_DELAYS) unless set on their own. One first attempt plus one per wait.
        'retry_delays' => array_values(array_filter(
            array_map('intval', explode(',', (string) env('WEBHOOK_RETRY_DELAYS', env('DIAN_RETRY_DELAYS', '2,5,15,45,120')))),
            fn (int $seconds): bool => $seconds > 0,
        )),
        'retry_jitter' => (float) env('WEBHOOK_RETRY_JITTER', env('DIAN_RETRY_JITTER', 0.2)),

        // A subscription is switched off after this many failed deliveries in a row.
        'disable_after_failures' => (int) env('WEBHOOK_DISABLE_AFTER_FAILURES', 20),

        // Local development sends webhooks to the local receiver on the internal network. Outside it,
        // set false to refuse private, loopback, link-local and reserved addresses.
        'allow_private_targets' => (bool) env('WEBHOOK_ALLOW_PRIVATE_TARGETS', true),
    ],

    // The local webhook receiver, a development tool inside the simulator service: the base of the
    // URLs that subscriptions use (internal network), and its admin API.
    'receiver' => [
        'url' => env('LOCAL_WEBHOOK_RECEIVER_URL', 'http://simulator:8602/webhook-receiver'),
        'admin_url' => env('DIAN_SIMULATOR_URL', 'http://simulator:8602'),
        'token' => env('DIAN_SIMULATOR_TOKEN', 'local-simulator-token'),
        'timeout' => (float) env('DIAN_SIMULATOR_TIMEOUT', 3),
    ],

    // Base URL make api-check calls: the API inside its own container.
    'api_check_url' => env('API_CHECK_URL', 'http://127.0.0.1:8000/api/v1'),

];
