<?php

// How long operational records are kept. The outbox relay prunes them once every PRUNE_EVERY_MINUTES
// (no container of its own); php artisan retention:prune does it at once. Documents, their files, their
// timelines, payments and accepted invitations are never pruned here.
return [

    // Minutes between two prunings by the relay.
    'every_minutes' => (int) env('PRUNE_EVERY_MINUTES', 60),

    // Days, counted from when each record was finished.
    'days' => [
        // Outbox events already published to the queue.
        'outbox_events' => (int) env('RETENTION_OUTBOX_DAYS', 7),

        // Calls to the DIAN of documents it accepted (the response the document keeps stays).
        'dian_transmissions' => (int) env('RETENTION_DIAN_TRANSMISSIONS_DAYS', 180),

        // Webhook deliveries that succeeded or failed, with their attempts and events.
        'webhook_deliveries' => (int) env('RETENTION_WEBHOOK_DELIVERIES_DAYS', 30),

        // Invitations that expired or were canceled.
        'invitations' => (int) env('RETENTION_INVITATIONS_DAYS', 30),

        // Jobs the queue gave up on.
        'failed_jobs' => (int) env('RETENTION_FAILED_JOBS_DAYS', 30),
    ],

];
