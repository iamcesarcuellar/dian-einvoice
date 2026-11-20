<?php

// Merged with the framework defaults: only the connections changed here are listed. Locally the queue is
// Redis (QUEUE_CONNECTION=redis). In the emulated cloud it is sqs: the queues of the CDK app, which the
// topic of document events fills (App\Support\Outbox\Sqs\SqsEventsQueue); the shared AWS settings come
// from config/aws.php.
return [

    'connections' => [

        'sqs' => [
            'driver' => 'sqs-events',

            // Where jobs the application dispatches itself go, such as invitation and password reset emails.
            'queue' => 'chain',

            // The queue of each channel (App\Support\Outbox\OutboxSubscribers): queue:work sqs --queue=chain
            // consumes the electronic document chain and the delivery, --queue=webhooks the webhooks.
            'queues' => [
                'chain' => env('SQS_CHAIN_QUEUE_URL'),
                'webhooks' => env('SQS_WEBHOOKS_QUEUE_URL'),
            ],

            // Seconds a receive waits for a message (long polling), at most 20.
            'wait_seconds' => (int) env('SQS_WAIT_SECONDS', 10),
        ],

    ],

];
