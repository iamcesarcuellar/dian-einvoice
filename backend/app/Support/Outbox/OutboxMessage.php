<?php

namespace App\Support\Outbox;

use App\Support\Observability\Correlation;
use Illuminate\Support\Carbon;
use JsonException;
use Throwable;

/**
 * An outbox event as a message of the emulated cloud and of production: the body is the event in JSON
 * and the attribute "type" carries its type, which the subscriptions of the topic filter on. The
 * subscriptions deliver raw messages, so the SQS message has the same body and attributes. A retry
 * scheduled for later carries available_at; the consumer leaves such a message invisible in its queue
 * until then. The correlation id of its journey goes in the body and as an attribute, so the consumer, and
 * whoever reads the message, can follow it.
 */
final class OutboxMessage
{
    /** Message attribute with the event type. */
    public const string TYPE = 'type';

    /** Message attribute with the company of the event. */
    public const string COMPANY = 'company_id';

    /** Message attribute with the correlation id of the journey of the event. */
    public const string CORRELATION = 'correlation_id';

    /** Longest wait SQS allows for one receive: 12 hours. */
    public const int MAX_VISIBILITY = 43_200;

    /**
     * The entry of the event in a PublishBatch call.
     *
     * @return array{Id: string, Message: string, MessageAttributes: array<string, array{DataType: string, StringValue: string}>}
     */
    public static function entry(OutboxEvent $event): array
    {
        return [
            'Id' => $event->id,
            'Message' => self::body($event),
            'MessageAttributes' => [
                self::TYPE => ['DataType' => 'String', 'StringValue' => $event->type],
                self::COMPANY => ['DataType' => 'String', 'StringValue' => $event->company_id],
                ...($event->correlationId() === null ? [] : [self::CORRELATION => ['DataType' => 'String', 'StringValue' => $event->correlationId()]]),
            ],
        ];
    }

    public static function body(OutboxEvent $event): string
    {
        return json_encode([
            'id' => $event->id,
            'company_id' => $event->company_id,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'type' => $event->type,
            'correlation_id' => $event->correlationId(),
            'payload' => $event->payload === [] ? (object) [] : $event->payload,
            'created_at' => ($event->created_at ?? Carbon::now())->toIso8601ZuluString('microsecond'),
            'available_at' => $event->available_at?->toIso8601ZuluString('microsecond'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The event an SQS message carries, or null when the message is not an outbox event: a job the
     * application dispatched to the queue itself, which has no type attribute.
     *
     * @param  array<mixed>  $message  A message of ReceiveMessage, with its message attributes.
     *
     * @throws UnreadableMessage When it carries a type but not a valid event.
     */
    public static function fromSqs(array $message): ?OutboxEvent
    {
        $attributes = is_array($message['MessageAttributes'] ?? null) ? $message['MessageAttributes'] : [];
        $typeAttribute = is_array($attributes[self::TYPE] ?? null) ? $attributes[self::TYPE] : null;

        if ($typeAttribute === null) {
            return null;
        }

        $type = is_string($typeAttribute['StringValue'] ?? null) ? $typeAttribute['StringValue'] : '';

        try {
            $data = json_decode(is_string($message['Body'] ?? null) ? $message['Body'] : '', true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnreadableMessage("the body of a {$type} message is not JSON: {$exception->getMessage()}.");
        }

        if (! is_array($data)) {
            throw new UnreadableMessage("the body of a {$type} message is not an object.");
        }

        foreach (['id', 'company_id', 'aggregate_type', 'aggregate_id', 'type', 'created_at'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new UnreadableMessage("a {$type} message has no {$field}.");
            }
        }

        if ($data['type'] !== $type) {
            throw new UnreadableMessage("a message of type attribute {$type} carries an event of type {$data['type']}.");
        }

        if (! is_array($data['payload'] ?? null)) {
            throw new UnreadableMessage("a {$type} message has no payload object.");
        }

        $availableAt = $data['available_at'] ?? null;

        if ($availableAt !== null && ! is_string($availableAt)) {
            throw new UnreadableMessage("a {$type} message has an available_at that is not a date.");
        }

        try {
            $createdAt = Carbon::parse($data['created_at'])->utc();
            $available = $availableAt === null ? null : Carbon::parse($availableAt)->utc();
        } catch (Throwable) {
            throw new UnreadableMessage("a {$type} message has dates that cannot be read.");
        }

        // The same attributes as a row of outbox_events, so the jobs read it as they read the table.
        return (new OutboxEvent)->newFromBuilder([
            'id' => $data['id'],
            'company_id' => $data['company_id'],
            'aggregate_type' => $data['aggregate_type'],
            'aggregate_id' => $data['aggregate_id'],
            'type' => $type,
            // A journey of the form of one, from the body or its attribute; null for messages written before.
            'correlation_id' => Correlation::accept(is_string($data['correlation_id'] ?? null) ? $data['correlation_id'] : self::attribute($message, self::CORRELATION)),
            'payload' => json_encode($data['payload'], JSON_THROW_ON_ERROR),
            'created_at' => $createdAt->format('Y-m-d H:i:s'),
            'available_at' => $available?->format('Y-m-d H:i:s'),
            'published_at' => null,
        ]);
    }

    /**
     * A string attribute of an SQS message, or null.
     *
     * @param  array<mixed>  $message
     */
    public static function attribute(array $message, string $name): ?string
    {
        $attributes = is_array($message['MessageAttributes'] ?? null) ? $message['MessageAttributes'] : [];
        $attribute = is_array($attributes[$name] ?? null) ? $attributes[$name] : [];

        return is_string($attribute['StringValue'] ?? null) ? $attribute['StringValue'] : null;
    }

    /** Seconds until the event may be handled; 0 when it is due. */
    public static function secondsUntilAvailable(OutboxEvent $event, ?Carbon $now = null): int
    {
        if ($event->available_at === null) {
            return 0;
        }

        $seconds = (int) ceil(($event->available_at->getTimestampMs() - ($now ?? Carbon::now())->getTimestampMs()) / 1000);

        return max(0, min($seconds, self::MAX_VISIBILITY));
    }
}
