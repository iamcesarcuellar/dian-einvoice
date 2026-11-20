<?php

namespace App\Support\Secrets;

use Closure;
use RuntimeException;
use Throwable;

/**
 * The credentials of an identity of the database read again from its secret after the database refused those
 * the process started with, as after a rotation (App\Support\Database\RefreshingMySqlConnector). The functions
 * read their secrets once per cold start, so the new credentials are kept for the rest of the execution
 * environment in a file only this user can read, in the temporary directory, where every later request or job
 * of the environment finds them; the next refusal reads the secret again and replaces them. A refusal within
 * REFRESH_SECONDS of the last read does not read again: Laravel reconnects once by itself on this error, and a
 * password that keeps failing would otherwise read the secret on every connection.
 */
final class DatabaseCredentials
{
    /** Seconds after a read during which a refusal does not read the secret again. */
    public const int REFRESH_SECONDS = 30;

    /**
     * @param  Closure(): SecretsManagerSecrets  $secrets  The Secrets Manager adapter, built only when a secret is read.
     */
    public function __construct(private readonly Closure $secrets) {}

    /**
     * The credentials an earlier refusal in this execution environment read, if any.
     *
     * @return array{username: string, password: string}|null
     */
    public function refreshed(string $secretId): ?array
    {
        $file = $this->file($secretId);

        if (! is_file($file)) {
            return null;
        }

        $stored = json_decode((string) file_get_contents($file), true);

        return is_array($stored) && is_string($stored['username'] ?? null) && is_string($stored['password'] ?? null)
            ? ['username' => $stored['username'], 'password' => $stored['password']]
            : null;
    }

    /**
     * Reads the current credentials of the secret and keeps them for the rest of the execution environment;
     * null when this environment read them less than REFRESH_SECONDS ago.
     *
     * @return array{username: string, password: string}|null
     */
    public function refresh(string $secretId): ?array
    {
        $file = $this->file($secretId);

        if (is_file($file) && time() - (int) filemtime($file) < self::REFRESH_SECONDS) {
            return null;
        }

        try {
            $settings = ($this->secrets)()->readDatabase($secretId);
        } catch (Throwable $exception) {
            throw new RuntimeException("The database refused the credentials of {$secretId}, and its secret could not be read again: {$exception->getMessage()}", previous: $exception);
        }

        $credentials = ['username' => $settings['DB_USERNAME'] ?? '', 'password' => $settings['DB_PASSWORD'] ?? ''];
        $temporary = $file.'.'.bin2hex(random_bytes(6));
        $previous = umask(0o077);

        try {
            if (file_put_contents($temporary, json_encode($credentials, JSON_THROW_ON_ERROR)) !== false) {
                chmod($temporary, 0o600);
                rename($temporary, $file);
            }
        } finally {
            umask($previous);
            @unlink($temporary);
        }

        return $credentials;
    }

    /** Forgets the credentials read again for a secret. */
    public function forget(string $secretId): void
    {
        @unlink($this->file($secretId));
    }

    public function file(string $secretId): string
    {
        return sys_get_temp_dir().'/dian-einvoice-database-'.hash('sha256', $secretId).'.json';
    }
}
