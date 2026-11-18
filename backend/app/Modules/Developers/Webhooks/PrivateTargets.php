<?php

namespace App\Modules\Developers\Webhooks;

/**
 * Whether a webhook URL points to a private network: loopback, private ranges, link-local and
 * reserved addresses, by IP or by the IPv4 addresses its host resolves to. Only enforced when
 * WEBHOOK_ALLOW_PRIVATE_TARGETS is false; local development sends to the internal network.
 */
final class PrivateTargets
{
    /** Why the URL is refused, or null when it is a public destination. */
    public static function reason(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return 'The URL has no host.';
        }

        $host = trim($host, '[]');

        if (strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')) {
            return 'The URL points to this machine.';
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);

        if ($addresses === []) {
            return "The host {$host} does not resolve.";
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return "The host {$host} resolves to {$address}, a private or reserved address.";
            }
        }

        return null;
    }
}
