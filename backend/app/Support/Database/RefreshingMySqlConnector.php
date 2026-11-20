<?php

namespace App\Support\Database;

use App\Support\Secrets\DatabaseCredentials;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use Throwable;

/**
 * The MySQL connector of the application: Laravel's, and one thing more for the functions of the emulated cloud
 * and of production. A function reads the credentials of its identity of the database once per cold start (the
 * configuration is cached with them), so after a rotation a warm function may hold a password the database no
 * longer takes. When the database refuses the credentials (error 1045) and they come from a secret (the
 * secrets_id of the connection, DB_SECRETS_ID), the connector reads that secret again through the Secrets
 * Manager adapter, keeps the new credentials for the rest of the execution environment (DatabaseCredentials) and
 * retries once with them. Any other error, a second refusal, credentials the secret still holds, a refusal right
 * after a read, and a connection without a secret fail as before.
 */
final class RefreshingMySqlConnector extends MySqlConnector
{
    /** ER_ACCESS_DENIED_ERROR: the user or its password is not one the database takes. */
    public const int ACCESS_DENIED = 1045;

    public function __construct(private readonly DatabaseCredentials $credentials) {}

    /**
     * @param  string  $dsn
     * @param  array<string, mixed>  $config
     * @param  array<int, mixed>  $options
     * @return PDO
     */
    public function createConnection($dsn, array $config, array $options)
    {
        $secretId = $config['secrets_id'] ?? null;

        if (! is_string($secretId) || $secretId === '') {
            return parent::createConnection($dsn, $config, $options);
        }

        // What an earlier refusal in this execution environment read wins over the credentials of the cold start.
        $config = [...$config, ...($this->credentials->refreshed($secretId) ?? [])];

        try {
            return parent::createConnection($dsn, $config, $options);
        } catch (PDOException $exception) {
            if (! self::refused($exception)) {
                throw $exception;
            }

            $fresh = $this->credentials->refresh($secretId);

            if ($fresh === null || ($fresh['username'] === ($config['username'] ?? null) && $fresh['password'] === ($config['password'] ?? null))) {
                throw $exception;
            }

            Log::warning('The database refused the credentials of this execution environment: they were read again from Secrets Manager, and the connection is retried once.', ['secret' => $secretId, 'user' => $fresh['username']]);

            return parent::createConnection($dsn, [...$config, ...$fresh], $options);
        }
    }

    /** Whether the database refused the user or its password. */
    public static function refused(Throwable $exception): bool
    {
        return $exception instanceof PDOException
            && ((int) $exception->getCode() === self::ACCESS_DENIED || str_contains($exception->getMessage(), '['.self::ACCESS_DENIED.']'));
    }
}
