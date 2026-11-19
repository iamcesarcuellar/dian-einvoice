<?php

namespace App\Modules\Developers\Webhooks;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Signatures of the Standard Webhooks specification (standardwebhooks.com): the secret is whsec_
 * and base64 of 32 random bytes; each attempt signs "{webhook-id}.{webhook-timestamp}.{body}" with
 * HMAC-SHA256 under the decoded secret and sends webhook-signature: v1,{base64 signature}.
 */
final class WebhookSignature
{
    public const string SECRET_PREFIX = 'whsec_';

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX.base64_encode(random_bytes(32));
    }

    /** The value of the webhook-signature header. */
    public static function sign(#[SensitiveParameter] string $secret, string $messageId, int $timestamp, string $body): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$messageId}.{$timestamp}.{$body}", self::key($secret), true));
    }

    /** Whether any v1 signature of the header matches, compared in constant time. */
    public static function verify(#[SensitiveParameter] string $secret, string $messageId, int $timestamp, string $body, string $header): bool
    {
        $expected = self::sign($secret, $messageId, $timestamp, $body);

        foreach (preg_split('/\s+/', trim($header)) ?: [] as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** The last characters of a secret, enough to recognize it. */
    public static function hint(#[SensitiveParameter] string $secret): string
    {
        return self::SECRET_PREFIX.'…'.substr($secret, -4);
    }

    private static function key(#[SensitiveParameter] string $secret): string
    {
        $encoded = str_starts_with($secret, self::SECRET_PREFIX) ? substr($secret, strlen(self::SECRET_PREFIX)) : $secret;
        $key = base64_decode($encoded, true);

        if ($key === false || $key === '') {
            throw new InvalidArgumentException('The webhook secret is not whsec_ followed by base64.');
        }

        return $key;
    }
}
