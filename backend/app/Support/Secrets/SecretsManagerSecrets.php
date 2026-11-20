<?php

namespace App\Support\Secrets;

use Aws\SecretsManager\SecretsManagerClient;
use JsonException;
use RuntimeException;

/**
 * Secrets Manager adapter of the secrets of the application. Two formats:
 *  - the secret of the application, which the CDK app creates (stack security): a JSON object of
 *    environment settings, such as {"APP_KEY": "...", "DB_PASSWORD": "..."}; names are environment
 *    variable names and values are text;
 *  - the secret of a managed database (Infrastructure task 3), in the format of Amazon RDS: username and
 *    password, and host, port and dbname when the secret is attached to the database; they become the
 *    settings DB_USERNAME, DB_PASSWORD, DB_HOST, DB_PORT and DB_DATABASE.
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
     * The settings of the database a secret in the format of Amazon RDS gives.
     *
     * @return array<string, string>
     */
    public function readDatabase(string $secretId): array
    {
        $secret = $this->object($secretId);
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
     * @return array<array-key, mixed>
     */
    private function object(string $secretId): array
    {
        $value = $this->client->getSecretValue(['SecretId' => $secretId])['SecretString'] ?? null;

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
