<?php

namespace App\Support\Secrets;

use App\Support\Aws\AwsSdk;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\Env;
use RuntimeException;
use Throwable;

/**
 * Reads the secrets of the application from AWS Secrets Manager when APP_SECRETS is secrets-manager (the
 * emulated cloud and production), right after the environment file and before the configuration, so
 * APP_KEY and the rest come from the secret and override whatever the environment says. DB_SECRETS_ID names
 * the secret of the identity of the database of the process, in the format of Amazon RDS (the migrations
 * identity in the console function, the application identity in every other one), whose username, password
 * and address win over the secret of the application. Locally (APP_SECRETS unset) nothing happens and the
 * environment file keeps them.
 *
 * Once per cold start: the functions cache the whole configuration when their execution environment
 * starts (Bref's Laravel bridge runs config:cache before the first request or job, BREF_LARAVEL_CACHE_CONFIG),
 * and nothing is read again while that cache exists: after a rotation, the connector of the database reads
 * the secret of the identity again when the database refuses the old password
 * (App\Support\Database\RefreshingMySqlConnector). Without a cached configuration, every PHP request boots the
 * application, so the values are kept for APP_SECRETS_CACHE_SECONDS (300 by default) in a file only this user
 * can read, in the temporary directory; a rotated secret is read again within that time.
 */
final class LoadSecrets
{
    public const string SOURCE = 'secrets-manager';

    public static function bootstrap(?CachesConfiguration $app = null): void
    {
        if (Env::get('APP_SECRETS') !== self::SOURCE) {
            return;
        }

        // The cached configuration already holds every value of the secrets.
        if ($app?->configurationIsCached() === true) {
            return;
        }

        $secretId = Env::get('APP_SECRETS_ID');

        if (! is_string($secretId) || $secretId === '') {
            throw new RuntimeException('APP_SECRETS is secrets-manager but APP_SECRETS_ID names no secret.');
        }

        $databaseSecretId = Env::get('DB_SECRETS_ID');
        $databaseSecretId = is_string($databaseSecretId) && $databaseSecretId !== '' ? $databaseSecretId : null;

        $ttl = Env::get('APP_SECRETS_CACHE_SECONDS');
        $values = self::cached($secretId, $databaseSecretId, is_numeric($ttl) ? (int) $ttl : 300);

        // The environment repository of Laravel never overwrites; the secret is authoritative here, so it
        // is written with the same adapters Laravel reads.
        $environment = RepositoryBuilder::createWithNoAdapters()
            ->addAdapter(ServerConstAdapter::class)
            ->addAdapter(EnvConstAdapter::class)
            ->addAdapter(PutenvAdapter::class)
            ->make();

        foreach ($values as $name => $value) {
            $environment->set($name, $value);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function cached(string $secretId, ?string $databaseSecretId, int $ttl): array
    {
        $file = sys_get_temp_dir().'/dian-einvoice-secrets-'.hash('sha256', $secretId."\n".$databaseSecretId).'.json';

        if ($ttl > 0 && is_file($file) && (time() - (int) filemtime($file)) < $ttl) {
            $cached = json_decode((string) file_get_contents($file), true);

            if (is_array($cached) && array_filter($cached, 'is_string') === $cached && array_filter(array_keys($cached), 'is_string') === array_keys($cached)) {
                /** @var array<string, string> $cached */
                return $cached;
            }
        }

        try {
            $values = self::read($secretId, $databaseSecretId);
        } catch (Throwable $exception) {
            throw new RuntimeException("The secrets of the application could not be read from Secrets Manager ({$secretId}): {$exception->getMessage()}", previous: $exception);
        }

        if ($ttl > 0) {
            $temporary = $file.'.'.bin2hex(random_bytes(6));
            $previous = umask(0o077);

            try {
                if (file_put_contents($temporary, json_encode($values, JSON_THROW_ON_ERROR)) !== false) {
                    chmod($temporary, 0o600);
                    rename($temporary, $file);
                }
            } finally {
                umask($previous);
                @unlink($temporary);
            }
        }

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private static function read(string $secretId, ?string $databaseSecretId): array
    {
        $client = AwsSdk::make([
            'region' => Env::get('AWS_DEFAULT_REGION', Env::get('AWS_REGION', 'us-east-1')),
            'endpoint' => Env::get('AWS_ENDPOINT_URL'),
            'key' => Env::get('AWS_ACCESS_KEY_ID'),
            'secret' => Env::get('AWS_SECRET_ACCESS_KEY'),
            'token' => Env::get('AWS_SESSION_TOKEN'),
            'connect_timeout' => 2,
            'timeout' => 5,
        ])->createSecretsManager();

        $secrets = new SecretsManagerSecrets($client);
        $values = $secrets->read($secretId);

        return $databaseSecretId === null ? $values : array_merge($values, $secrets->readDatabase($databaseSecretId));
    }
}
