<?php

namespace Tests\Unit\Developers;

use App\Modules\Developers\Webhooks\PrivateTargets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * With private targets refused, a webhook only reaches global unicast addresses: a URL that names
 * (or resolves to) anything else is refused before the call, and the call goes to the address
 * checked. These are the address forms an SSRF tries, IPv4 and IPv6.
 */
final class PrivateTargetsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'public IPv4' => ['93.184.215.14', true];
        yield 'public IPv6' => ['2606:4700:4700::1111', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'loopback IPv6' => ['::1', false];
        yield 'unspecified' => ['0.0.0.0', false];
        yield 'private 10/8' => ['10.0.0.1', false];
        yield 'private 172.16/12 (the compose network)' => ['172.24.0.5', false];
        yield 'private 192.168/16' => ['192.168.1.1', false];
        yield 'cloud metadata, link-local' => ['169.254.169.254', false];
        yield 'shared address space 100.64/10 (another cloud metadata)' => ['100.100.100.200', false];
        yield 'benchmarking 198.18/15' => ['198.18.0.1', false];
        yield 'multicast' => ['224.0.0.1', false];
        yield 'broadcast' => ['255.255.255.255', false];
        yield 'link-local IPv6' => ['fe80::1', false];
        yield 'unique local IPv6 (cloud metadata over IPv6)' => ['fd00:ec2::254', false];
        yield 'multicast IPv6' => ['ff02::1', false];
        yield 'IPv4-mapped loopback' => ['::ffff:127.0.0.1', false];
        yield 'IPv4-mapped metadata' => ['::ffff:169.254.169.254', false];
        yield 'NAT64 of the metadata address' => ['64:ff9b::a9fe:a9fe', false];
        yield 'NAT64 of a public address' => ['64:ff9b::5db8:d70e', true];
        yield '6to4 of the metadata address' => ['2002:a9fe:a9fe::1', false];
        yield 'documentation IPv6' => ['2001:db8::1', false];
    }

    #[DataProvider('addresses')]
    public function test_only_global_unicast_addresses_are_public(string $address, bool $public): void
    {
        $this->assertSame($public, PrivateTargets::isPublic($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'localhost' => ['http://localhost:8602/x'];
        yield 'a subdomain of localhost' => ['https://evil.localhost/x'];
        yield 'IPv6 literal' => ['http://[::1]:8000/x'];
        yield 'IPv4-mapped literal' => ['http://[::ffff:127.0.0.1]/x'];
        yield 'decimal IPv4, resolved by the system' => ['http://2130706433/x'];
        yield 'hexadecimal IPv4, resolved by the system' => ['http://0x7f.1/x'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'no host' => ['https:///x'];
    }

    #[DataProvider('refusedUrls')]
    public function test_urls_that_reach_a_private_address_are_refused(string $url): void
    {
        $this->assertNotNull(PrivateTargets::inspect($url)['reason']);
    }

    public function test_a_public_literal_is_allowed_and_pinned_to_its_address(): void
    {
        $target = PrivateTargets::inspect('https://93.184.215.14/hooks');

        $this->assertNull($target['reason']);
        $this->assertSame('93.184.215.14:443:93.184.215.14', PrivateTargets::pin($target['host'], $target['port'], $target['addresses'][0] ?? ''));
        $this->assertSame('example.test:8443:[2606:4700:4700::1111]', PrivateTargets::pin('example.test', 8443, '2606:4700:4700::1111'));
    }
}
