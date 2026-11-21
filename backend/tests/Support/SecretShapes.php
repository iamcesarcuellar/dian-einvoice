<?php

namespace Tests\Support;

use Illuminate\Support\Str;

/**
 * Values with the form of a secret, for the tests that need one (the redactor of the logs, for one), built at run
 * time and never written as literals: the secret scan of the repository and of its history (gitleaks, make security)
 * then never meets one in a test, and needs no exception for it. Each call gives a new random value, which is not and
 * never was a key of anything. A public test value whose result a test checks (a reference vector) is the exception:
 * it stays literal, allowed in .gitleaks.toml in its file only.
 */
final class SecretShapes
{
    /** An APP_KEY of Laravel: base64: and 32 random bytes. */
    public static function appKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /** An AWS access key id: AKIA and 16 upper-case letters and digits. */
    public static function awsAccessKeyId(): string
    {
        return 'AKIA'.strtoupper(Str::random(16));
    }

    /** An API key of the platform: dei_, its public prefix and its secret part. */
    public static function apiKey(): string
    {
        return 'dei_'.Str::random(12).'_'.Str::random(32);
    }

    /** The signing secret of a webhook subscription (Standard Webhooks): whsec_ and base64. */
    public static function webhookSecret(): string
    {
        return 'whsec_'.base64_encode(random_bytes(24));
    }

    /** A JSON Web Token: three parts in base64url, the first two JSON. */
    public static function jwt(): string
    {
        $part = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return $part('{"alg":"HS256","typ":"JWT"}').'.'.$part('{"sub":"'.Str::random(8).'"}').'.'.$part(random_bytes(32));
    }

    /** A private key in PEM. */
    public static function privateKeyPem(): string
    {
        $label = 'PRIVATE KEY';

        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode(random_bytes(96)), 64, "\n")."-----END {$label}-----";
    }

    /** A password of letters and digits. */
    public static function password(): string
    {
        return Str::random(16);
    }

    /** An opaque secret of 40 characters, with letters and digits both. */
    public static function opaque(): string
    {
        return 'k'.bin2hex(random_bytes(19)).'7';
    }
}
