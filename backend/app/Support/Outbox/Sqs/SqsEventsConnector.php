<?php

namespace App\Support\Outbox\Sqs;

use App\Support\Aws\AwsSdk;
use App\Support\Outbox\OutboxSubscribers;
use Aws\Sqs\SqsClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * Queue driver sqs-events (connection sqs of config/queue.php): the SQS queues of the CDK app with the
 * shared AWS settings of config/aws.php.
 */
final class SqsEventsConnector implements ConnectorInterface
{
    /**
     * @param  array<string, mixed>  $aws  config('aws')
     */
    public function __construct(
        private readonly array $aws,
        private readonly OutboxSubscribers $subscribers,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): SqsEventsQueue
    {
        $queues = [];

        foreach (is_array($config['queues'] ?? null) ? $config['queues'] : [] as $channel => $url) {
            if (is_string($channel) && is_string($url) && $url !== '') {
                $queues[$channel] = $url;
            }
        }

        return new SqsEventsQueue(
            new SqsClient(AwsSdk::arguments($this->aws)),
            $queues,
            $this->subscribers,
            is_string($config['queue'] ?? null) ? $config['queue'] : OutboxSubscribers::CHAIN,
        );
    }
}
