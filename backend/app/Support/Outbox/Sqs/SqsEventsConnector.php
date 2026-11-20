<?php

namespace App\Support\Outbox\Sqs;

use App\Support\Aws\AwsSdk;
use App\Support\Outbox\OutboxSubscribers;
use Aws\Sqs\SqsClient;
use Illuminate\Queue\Connectors\ConnectorInterface;

/**
 * Queue driver sqs-events (connection sqs of config/queue.php): the SQS queues of the CDK app with the
 * shared AWS settings of config/aws.php. Receives wait up to wait_seconds for a message (long polling),
 * so the HTTP timeout of its client is longer than that.
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
        $wait = max(0, min(20, is_numeric($config['wait_seconds'] ?? null) ? (int) $config['wait_seconds'] : 10));
        $timeout = is_numeric($this->aws['timeout'] ?? null) ? (float) $this->aws['timeout'] : 10.0;

        $queues = [];

        foreach (is_array($config['queues'] ?? null) ? $config['queues'] : [] as $channel => $url) {
            if (is_string($channel) && is_string($url) && $url !== '') {
                $queues[$channel] = $url;
            }
        }

        return new SqsEventsQueue(
            new SqsClient(AwsSdk::arguments($this->aws, timeout: $wait + $timeout)),
            $queues,
            $this->subscribers,
            $wait,
            is_string($config['queue'] ?? null) ? $config['queue'] : OutboxSubscribers::CHAIN,
        );
    }
}
