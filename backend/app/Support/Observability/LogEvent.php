<?php

namespace App\Support\Observability;

/**
 * The events the backend writes, each one of the events of resources/observability/log-schema.json (a test holds
 * the two together). The metric filters of the CDK app count them; docs/observability.md says what each means.
 * The TypeScript functions write the others (edge.refused, edge.secret, alarm.notification).
 */
enum LogEvent: string
{
    case HttpRequest = 'http.request';
    case AuthRefused = 'auth.refused';
    case DocumentIssued = 'document.issued';
    case OutboxPublished = 'outbox.published';
    case OutboxRelayed = 'outbox.relayed';
    case OutboxRelayRun = 'outbox.relay_run';
    case OutboxSwept = 'outbox.swept';
    case QueueJob = 'queue.job';
    case DianCall = 'dian.call';
    case DianResponse = 'dian.response';
    case DianRule = 'dian.rule';
    case DeliveryEmail = 'delivery.email';
    case WebhookDelivery = 'webhook.delivery';
    case SecretReread = 'secret.reread';
    case ConsoleCommand = 'console.command';
    case SchedulerTask = 'scheduler.task';
    case RuntimeOutput = 'runtime.output';
}
