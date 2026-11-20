<?php

namespace App\Support\Aws;

use Aws\Sdk;

/**
 * The settings every AWS client of the application shares (config/aws.php): region, the endpoint of the
 * local emulator when there is one, credentials when given, timeouts and retries. Only the AWS adapters
 * (S3, KMS, SNS, SQS, Secrets Manager, DynamoDB, SES) build clients, and always from these settings.
 */
final class AwsSdk
{
    /**
     * @param  array<string, mixed>  $config  config('aws'), or the same keys read from the environment.
     */
    public static function make(array $config): Sdk
    {
        return new Sdk(self::arguments($config));
    }

    /**
     * Constructor arguments of an AWS client.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function arguments(array $config, ?float $timeout = null): array
    {
        $arguments = [
            'region' => self::text($config, 'region') ?? 'us-east-1',
            'version' => 'latest',
            'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
            'retries' => ['mode' => 'standard', 'max_attempts' => max(1, (int) self::number($config, 'attempts', 3))],
            'http' => [
                'connect_timeout' => self::number($config, 'connect_timeout', 2),
                'timeout' => $timeout ?? self::number($config, 'timeout', 10),
            ],
        ];

        if (($endpoint = self::text($config, 'endpoint')) !== null) {
            $arguments['endpoint'] = $endpoint;
        }

        $key = self::text($config, 'key');
        $secret = self::text($config, 'secret');

        if ($key !== null && $secret !== null) {
            $arguments['credentials'] = array_filter(['key' => $key, 'secret' => $secret, 'token' => self::text($config, 'token')]);
        }

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function number(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function text(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
