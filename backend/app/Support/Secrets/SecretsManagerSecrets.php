<?php

namespace App\Support\Secrets;

use Aws\SecretsManager\SecretsManagerClient;
use JsonException;
use RuntimeException;

/**
 * Secrets Manager adapter of the secrets of the application: one secret whose value is a JSON object of
 * environment settings, such as {"APP_KEY": "...", "DB_PASSWORD": "..."}, which the CDK app creates
 * (stack security). Names are environment variable names; values are text.
 */
final class SecretsManagerSecrets
{
    public function __construct(private readonly SecretsManagerClient $client) {}

    /**
     * @return array<string, string>
     */
    public function read(string $secretId): array
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

        $secrets = [];

        foreach ($decoded as $name => $secret) {
            if (! is_string($name) || preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1 || ! is_scalar($secret)) {
                throw new RuntimeException("The secret {$secretId} holds a setting that is not NAME=text.");
            }

            $secrets[$name] = (string) $secret;
        }

        return $secrets;
    }
}
