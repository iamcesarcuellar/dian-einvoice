<?php

// Merged with the framework defaults: only the connections changed here are listed. Locally the queue is
// Redis (QUEUE_CONNECTION=redis). In the emulated cloud and in production it is sqs: the queues of the CDK
// app, which the topic of document events fills and the consumer functions take
// (App\Support\Outbox\Sqs\SqsEventsHandler); the shared AWS settings come from config/aws.php.
return [

    'connections' => [

        'sqs' => [
            'driver' => 'sqs-events',

            // Where jobs the application dispatches itself go, such as invitation and password reset emails.
            'queue' => 'chain',

            // The queue of each channel (App\Support\Outbox\OutboxSubscribers): the chain function consumes
            // the electronic document chain and the delivery, the webhooks function the webhooks.
            'queues' => [
                'chain' => env('SQS_CHAIN_QUEUE_URL'),
                'webhooks' => env('SQS_WEBHOOKS_QUEUE_URL'),
            ],
        ],

    ],

];
