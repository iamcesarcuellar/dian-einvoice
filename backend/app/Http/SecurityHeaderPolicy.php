<?php

namespace App\Http;

use LogicException;

/**
 * The security headers of the platform as resources/http/security-headers.json declares them (config/headers.php),
 * typed: the headers every answer carries, and those of each kind of document (the web app, which the edge serves,
 * the data of the API, its files and the page of the API reference) with their Content-Security-Policy, rendered
 * the way the CDK app renders it for the edge (infra/cdk/lib/security-headers.ts).
 */
final class SecurityHeaderPolicy
{
    /** The headers of every answer, such as X-Content-Type-Options. */
    public static function common(string $name): string
    {
        return self::string(config("headers.common.{$name}"), "common {$name}");
    }

    /**
     * The headers of one kind of document besides its policy, such as Referrer-Policy.
     *
     * @return array<string, string>
     */
    public static function headers(string $document): array
    {
        return self::strings(config("headers.documents.{$document}.headers"), "{$document} headers");
    }

    /**
     * The Content-Security-Policy of one kind of document: each directive with its sources, in the order the file
     * declares them, as "directive source source; directive source". Extra sources (the hashes of the inline
     * scripts of one answer) go after those of their directive.
     *
     * @param  array<string, list<string>>  $extra
     */
    public static function contentSecurityPolicy(string $document, array $extra = []): string
    {
        $directives = self::strings(config("headers.documents.{$document}.contentSecurityPolicy"), "{$document} policy");
        $rendered = [];

        foreach ($directives as $directive => $sources) {
            $rendered[] = trim(implode(' ', [$directive, $sources, ...($extra[$directive] ?? [])]));
        }

        foreach (array_diff_key($extra, $directives) as $directive => $sources) {
            throw new LogicException("The {$document} policy has no directive {$directive} for ".implode(' ', $sources).'.');
        }

        return implode('; ', $rendered);
    }

    /**
     * The Subresource Integrity of the third-party files of one kind of document, by their URL.
     *
     * @return array<string, string>
     */
    public static function subresourceIntegrity(string $document): array
    {
        return self::strings(config("headers.documents.{$document}.subresourceIntegrity", []), "{$document} integrity");
    }

    private static function string(mixed $value, string $what): string
    {
        if (! is_string($value) || $value === '') {
            throw new LogicException("resources/http/security-headers.json has no {$what}.");
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $values, string $what): array
    {
        if (! is_array($values)) {
            throw new LogicException("resources/http/security-headers.json has no {$what}.");
        }

        $strings = [];

        foreach ($values as $name => $value) {
            $strings[(string) $name] = self::string($value, "{$what} {$name}");
        }

        return $strings;
    }
}
