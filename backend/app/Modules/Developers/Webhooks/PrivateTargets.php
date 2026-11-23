<?php

namespace App\Modules\Developers\Webhooks;

/**
 * Whether a webhook URL may be reached when private destinations are refused
 * (WEBHOOK_ALLOW_PRIVATE_TARGETS=false, the default outside local development). Every address the
 * host resolves to now, IPv4 and IPv6, must be a global unicast address: loopback, private,
 * link-local (cloud metadata at 169.254.169.254 included), shared (100.64.0.0/10), reserved,
 * documentation, multicast, unique local (fd00:ec2::254 included), IPv4-mapped and NAT64 forms of
 * those are refused. The sender then connects to the address checked here and to no other, so a
 * second DNS answer cannot move it (DNS rebinding), and never follows a redirect.
 */
final class PrivateTargets
{
    /** What FILTER_FLAG_GLOBAL_RANGE (RFC 6890) lets through and a webhook must still not reach. */
    private const array MULTICAST = ['224.0.0.0/4', 'ff00::/8'];

    /** NAT64 (RFC 6052): what is reached is the IPv4 address in the last 32 bits. */
    private const string NAT64 = '64:ff9b::/96';

    /** The port a webhook URL is sent to: its own, or that of its scheme. */
    public static function port(string $url): int
    {
        $parts = parse_url($url);

        return is_array($parts) && isset($parts['port']) ? $parts['port'] : ((is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https') ? 443 : 80);
    }

    /** Why the URL is refused, in the language of the application, or null when every address of its host is public. */
    public static function reason(string $url): ?string
    {
        return self::explain(self::inspect($url));
    }

    /**
     * Why an inspected URL is refused, in the language of the application (developers::webhooks), or null.
     *
     * @param  array{reason: string|null, replace: array<string, string>, host: string, port: int, addresses: list<string>}  $target
     */
    public static function explain(array $target): ?string
    {
        return $target['reason'] === null ? null : (string) __("developers::webhooks.{$target['reason']}", $target['replace']);
    }

    /**
     * The public addresses the URL may be sent to, or why it may not: the key of the reason in developers::webhooks,
     * with what fills it in.
     *
     * @return array{reason: string|null, replace: array<string, string>, host: string, port: int, addresses: list<string>}
     */
    public static function inspect(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) && isset($parts['host']) ? strtolower(rtrim(trim($parts['host'], '[]'), '.')) : '';
        $port = self::port($url);

        if ($host === '') {
            return self::refused('no_host', $host, $port);
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return self::refused('local_host', $host, $port);
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        if ($addresses === []) {
            return self::refused('unresolved', $host, $port, ['host' => $host]);
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                return self::refused('private', $host, $port, ['host' => $host, 'address' => $address]);
            }
        }

        return ['reason' => null, 'replace' => [], 'host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    /**
     * A refused target: the key of the reason and what fills it in, with no address to send to.
     *
     * @param  array<string, string>  $replace
     * @return array{reason: string, replace: array<string, string>, host: string, port: int, addresses: list<string>}
     */
    private static function refused(string $reason, string $host, int $port, array $replace = []): array
    {
        return ['reason' => $reason, 'replace' => $replace, 'host' => $host, 'port' => $port, 'addresses' => []];
    }

    /** Whether one address is a global unicast address a webhook may reach. */
    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        foreach (self::MULTICAST as $range) {
            if (self::inRange($address, $range)) {
                return false;
            }
        }

        if (self::inRange($address, self::NAT64)) {
            $packed = (string) inet_pton($address);
            $embedded = inet_ntop(substr($packed, 12));

            return is_string($embedded) && self::isPublic($embedded);
        }

        return true;
    }

    /** The entry for CURLOPT_RESOLVE that ties the host of the URL to the address checked. */
    public static function pin(string $host, int $port, string $address): string
    {
        return sprintf('%s:%d:%s', $host, $port, str_contains($address, ':') ? "[{$address}]" : $address);
    }

    /**
     * IPv4 addresses as the system resolves them (hosts file included) and IPv6 addresses from DNS.
     *
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private static function inRange(string $address, string $range): bool
    {
        [$network, $bits] = explode('/', $range);
        $packed = inet_pton($address);
        $base = inet_pton($network);

        if ($packed === false || $base === false || strlen($packed) !== strlen($base)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (substr($packed, 0, $bytes) !== substr($base, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $rest) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($base[$bytes]) & $mask);
    }
}
