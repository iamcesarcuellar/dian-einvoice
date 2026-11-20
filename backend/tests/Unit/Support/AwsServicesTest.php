<?php

namespace Tests\Unit\Support;

use Aws\Api\ApiProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Aws\manifest;

/**
 * The AWS services the adapters of the application call, as the code builds their clients, are exactly those
 * resources/aws/services.json lists by the name of their VPC endpoint. The CDK app gives each listed service a
 * path from the private subnets of the functions (infra/cdk/lib/network-stack.ts), and the emulator would not
 * notice a function without one: this test fails first, when an adapter starts to call a service the list does
 * not name. The development tools of the emulated cloud (app/Support/Cloud), which refuse to run against AWS,
 * do not count.
 */
final class AwsServicesTest extends TestCase
{
    public function test_the_list_of_aws_services_names_every_service_the_adapters_call(): void
    {
        $base = dirname(__DIR__, 3);
        $namespaces = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base.'/app')) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || str_starts_with($file->getPathname(), $base.'/app/Support/Cloud/')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            // Clients imported (use Aws\S3\...) or built by the factory of the SDK (->createS3()).
            preg_match_all('/^use Aws\\\\([A-Za-z0-9]+)\\\\/m', $code, $imports);
            preg_match_all('/->create([A-Z][A-Za-z0-9]*)\(\)/', $code, $factories);

            foreach ([...$imports[1], ...$factories[1]] as $namespace) {
                if (class_exists("Aws\\{$namespace}\\{$namespace}Client")) {
                    $namespaces[$namespace] = true;
                }
            }
        }

        // Email goes through Laravel's mailer over the SES v2 API.
        if (str_contains((string) file_get_contents($base.'/config/mail.php'), "'transport' => 'ses-v2'")) {
            $namespaces['SesV2'] = true;
        }

        $this->assertNotEmpty($namespaces);
        $provider = ApiProvider::defaultProvider();
        $endpoints = [];

        $identifiers = [];

        foreach ((array) manifest() as $identifier => $entry) {
            if (is_array($entry) && is_string($entry['namespace'] ?? null)) {
                $identifiers[$entry['namespace']] = (string) $identifier;
            }
        }

        foreach (array_keys($namespaces) as $namespace) {
            $identifier = $identifiers[$namespace] ?? null;
            $this->assertIsString($identifier, "{$namespace} is a service of the AWS SDK");
            $api = ApiProvider::resolve($provider, 'api', $identifier, 'latest');
            $metadata = is_array($api['metadata'] ?? null) ? $api['metadata'] : [];
            $this->assertIsString($metadata['endpointPrefix'] ?? null);
            $endpoints[] = $metadata['endpointPrefix'];
        }

        sort($endpoints);
        $listed = json_decode((string) file_get_contents($base.'/resources/aws/services.json'), true);
        $this->assertIsArray($listed);
        $this->assertIsArray($listed['services'] ?? null);
        $names = array_keys($listed['services']);
        sort($names);

        $this->assertSame($endpoints, $names, 'resources/aws/services.json lists every AWS service the adapters call, and no other (its CDK test then gives each one a path from the private subnets)');
    }
}
