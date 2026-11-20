<?php

namespace App\Support\Outbox;

use Illuminate\Console\Command;

/**
 * The routes of the topic of document events to the SQS queues, from the subscriptions of the modules:
 * the event types each channel has a job for. The CDK app builds the filters of the subscriptions of the
 * topic from resources/outbox/routes.json (mounted read-only into its container); a test fails when that
 * file no longer matches the subscriptions, and --write brings it up to date.
 */
final class OutboxRoutesCommand extends Command
{
    public const string FILE = 'outbox/routes.json';

    protected $signature = 'outbox:routes {--write : Write resources/outbox/routes.json instead of printing it}';

    protected $description = 'Print the event types each SQS queue receives from the topic of document events (the filters of the CDK app)';

    public function handle(OutboxSubscribers $subscribers): int
    {
        $json = self::json($subscribers);

        if ($this->option('write')) {
            file_put_contents(resource_path(self::FILE), $json);
            $this->info('Written to resources/'.self::FILE.'.');

            return self::SUCCESS;
        }

        $this->output->write($json);

        return self::SUCCESS;
    }

    public static function json(OutboxSubscribers $subscribers): string
    {
        return json_encode(['channels' => $subscribers->routes()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
