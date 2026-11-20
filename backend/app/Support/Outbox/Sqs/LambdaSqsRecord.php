<?php

namespace App\Support\Outbox\Sqs;

use Bref\Event\Sqs\SqsRecord;

/**
 * An SQS record of a Lambda event, in the shape ReceiveMessage gives a message (MessageId, ReceiptHandle,
 * Body, Attributes, MessageAttributes with DataType and StringValue), which OutboxMessage::fromSqs() and
 * Laravel's SqsJob read. Lambda writes the attributes of a record in camel case (dataType, stringValue).
 *
 * The subscriptions of the topic deliver raw messages, so the body is the event and its attributes are
 * those of the message. A subscription without raw delivery wraps the event in the notification envelope
 * of SNS (Type Notification, Message, MessageAttributes with Type and Value): the envelope is unwrapped,
 * so both shapes reach the same job.
 */
final class LambdaSqsRecord
{
    /**
     * @return array{MessageId: string, ReceiptHandle: string, Body: string, Attributes: array<string, string>, MessageAttributes: array<string, array<string, string>>}
     */
    public static function toMessage(SqsRecord $record): array
    {
        $data = $record->toArray();
        $attributes = [];

        foreach (is_array($data['attributes'] ?? null) ? $data['attributes'] : [] as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $attributes[$name] = (string) $value;
            }
        }

        $message = [
            'MessageId' => $record->getMessageId(),
            'ReceiptHandle' => $record->getReceiptHandle(),
            'Body' => $record->getBody(),
            'Attributes' => $attributes,
            'MessageAttributes' => self::messageAttributes(is_array($data['messageAttributes'] ?? null) ? $data['messageAttributes'] : []),
        ];

        return self::unwrapNotification($message);
    }

    /**
     * @param  array<mixed>  $attributes  Lambda's messageAttributes.
     * @return array<string, array<string, string>>
     */
    private static function messageAttributes(array $attributes): array
    {
        $converted = [];

        foreach ($attributes as $name => $attribute) {
            if (! is_string($name) || ! is_array($attribute)) {
                continue;
            }

            $converted[$name] = array_filter([
                'DataType' => is_string($attribute['dataType'] ?? null) ? $attribute['dataType'] : 'String',
                'StringValue' => is_string($attribute['stringValue'] ?? null) ? $attribute['stringValue'] : null,
                'BinaryValue' => is_string($attribute['binaryValue'] ?? null) ? (string) base64_decode($attribute['binaryValue'], true) : null,
            ], fn (?string $value): bool => $value !== null);
        }

        return $converted;
    }

    /**
     * The event inside an SNS notification envelope, with the attributes of the notification; any other
     * message as it is.
     *
     * @param  array{MessageId: string, ReceiptHandle: string, Body: string, Attributes: array<string, string>, MessageAttributes: array<string, array<string, string>>}  $message
     * @return array{MessageId: string, ReceiptHandle: string, Body: string, Attributes: array<string, string>, MessageAttributes: array<string, array<string, string>>}
     */
    private static function unwrapNotification(array $message): array
    {
        if ($message['MessageAttributes'] !== [] || ! str_contains($message['Body'], '"Notification"')) {
            return $message;
        }

        $envelope = json_decode($message['Body'], true);

        if (! is_array($envelope) || ($envelope['Type'] ?? null) !== 'Notification' || ! is_string($envelope['Message'] ?? null) || ! is_string($envelope['TopicArn'] ?? null)) {
            return $message;
        }

        $attributes = [];

        foreach (is_array($envelope['MessageAttributes'] ?? null) ? $envelope['MessageAttributes'] : [] as $name => $attribute) {
            if (is_string($name) && is_array($attribute) && is_string($attribute['Value'] ?? null)) {
                $attributes[$name] = ['DataType' => is_string($attribute['Type'] ?? null) ? $attribute['Type'] : 'String', 'StringValue' => $attribute['Value']];
            }
        }

        $message['Body'] = $envelope['Message'];
        $message['MessageAttributes'] = $attributes;

        return $message;
    }
}
