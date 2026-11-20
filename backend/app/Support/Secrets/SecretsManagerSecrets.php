<?php

namespace App\Support\Secrets;

use Aws\SecretsManager\Exception\SecretsManagerException;
use Aws\SecretsManager\SecretsManagerClient;
use JsonException;
use RuntimeException;

/**
 * Secrets Manager adapter of the secrets of the application. Two formats:
 *  - the secret of the application, which the CDK app creates (stack security): a JSON object of
 *    environment settings, such as {"APP_KEY": "...", "DB_PASSWORD": "..."}; names are environment
 *    variable names and values are text;
 *  - the secret of an identity of the database, in the format of Amazon RDS: username and password, dbname,
 *    and host and port once attached to the managed database; they become the settings DB_USERNAME,
 *    DB_PASSWORD, DB_HOST, DB_PORT and DB_DATABASE. A rotation keeps the version before it as AWSPREVIOUS:
 *    with alternating users, the other user, still valid until the next rotation.
 */
final class SecretsManagerSecrets
{
    /** The fields of a database secret and the setting each one becomes. */
    public const array DATABASE_FIELDS = [
        'username' => 'DB_USERNAME',
        'password' => 'DB_PASSWORD',
        'host' => 'DB_HOST',
        'port' => 'DB_PORT',
        'dbname' => 'DB_DATABASE',
    ];

    public function __construct(private readonly SecretsManagerClient $client) {}

    /**
     * @return array<string, string>
     */
    public function read(string $secretId): array
    {
        $secrets = [];

        foreach ($this->object($secretId) as $name => $secret) {
            if (! is_string($name) || preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1 || ! is_scalar($secret)) {
                throw new RuntimeException("The secret {$secretId} holds a setting that is not NAME=text.");
            }

            $secrets[$name] = (string) $secret;
        }

        return $secrets;
    }

    /**
     * The settings of the database a secret in the format of Amazon RDS gives, in its current version or in the
     * version a staging label names.
     *
     * @return array<string, string>
     */
    public function readDatabase(string $secretId, ?string $stage = null): array
    {
        $secret = $this->object($secretId, $stage);
        $settings = [];

        foreach (self::DATABASE_FIELDS as $field => $setting) {
            if (array_key_exists($field, $secret)) {
                if (! is_scalar($secret[$field])) {
                    throw new RuntimeException("The database secret {$secretId} has a {$field} that is not text.");
                }

                $settings[$setting] = (string) $secret[$field];
            }
        }

        if (! isset($settings['DB_USERNAME'], $settings['DB_PASSWORD'])) {
            throw new RuntimeException("The database secret {$secretId} has no username and password.");
        }

        return $settings;
    }

    /**
     * The user of a database secret in the version a staging label names, such as AWSPREVIOUS, or null when no
     * version carries the label (a secret not rotated yet) or that version names no user of the database.
     */
    public function usernameOf(string $secretId, string $stage): ?string
    {
        try {
            return $this->readDatabase($secretId, $stage)['DB_USERNAME'];
        } catch (SecretsManagerException $exception) {
            if ($exception->getAwsErrorCode() === 'ResourceNotFoundException') {
                return null;
            }

            throw $exception;
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function object(string $secretId, ?string $stage = null): array
    {
        $value = $this->client->getSecretValue(array_filter(['SecretId' => $secretId, 'VersionStage' => $stage]))['SecretString'] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("The secret {$secretId} has no text value.");
        }

        try {
            $decoded = json_decode($value, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("The secret {$secretId} is not a JSON object.");
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("The secret {$secretId} is not a JSON object.");
        }

        return $decoded;
    }
}
