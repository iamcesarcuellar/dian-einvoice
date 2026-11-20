<?php

namespace App\Support\Database;

use App\Support\Secrets\SecretsManagerSecrets;
use Aws\Sdk;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

/**
 * php artisan db:application-identity: creates or updates the application identity of the database, the user
 * every function but the console connects as. It reads and writes the data of the application and can change
 * nothing of its schema. The console function runs it with its own identity, the migrations identity, after
 * the migrations: the same command in the emulated cloud (make cloud-up, on the MySQL of compose) and in
 * production (on the managed database). Idempotent:
 *  - the user of the current version of its secret gets the password of that version, TLS required, and
 *    exactly SELECT, INSERT, UPDATE and DELETE on the database of the application;
 *  - the user of the previous version, which a rotation with alternating users keeps valid until the next one,
 *    gets the same privileges and TLS, and keeps its password;
 *  - then it connects as the identity and checks that it holds those privileges and nothing more.
 */
final class ApplicationIdentityCommand extends Command
{
    protected $signature = 'db:application-identity';

    protected $description = 'Create or update the application identity of the database: it reads and writes data and changes nothing of the schema';

    /** All the application identity may do: data, never the schema. */
    public const array PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE'];

    /** The connection the command checks the identity with. */
    private const string CHECK = 'application-identity';

    public function handle(Sdk $sdk): int
    {
        $secretId = config('database.application_identity.secret');

        if (! is_string($secretId) || $secretId === '') {
            $this->error('DB_APPLICATION_SECRETS_ID names no secret: this command runs in the console function of the emulated cloud and of production.');

            return self::FAILURE;
        }

        $secrets = new SecretsManagerSecrets($sdk->createSecretsManager());
        $current = $secrets->readDatabase($secretId);
        $previous = $secrets->usernameOf($secretId, 'AWSPREVIOUS');
        $user = $current['DB_USERNAME'];
        $connection = DB::connection();
        $settings = (array) config('database.connections.'.$connection->getName());
        $database = (string) $connection->getDatabaseName();

        foreach ([$user, $previous ?? $user] as $name) {
            if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $name) !== 1) {
                throw new RuntimeException("The user {$name} of the application identity is not a plain MySQL user name.");
            }
        }

        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            throw new RuntimeException("The database {$database} is not a plain MySQL database name.");
        }

        $migrations = $this->currentUser($connection);
        // Account management with names checked above and the password quoted by the driver, straight to PDO:
        // these statements take no bound parameters for names.
        $pdo = $connection->getPdo();
        $password = $pdo->quote($current['DB_PASSWORD'] ?? '');
        $grant = fn (string $name): string => sprintf('GRANT %s ON `%s`.* TO %s', implode(', ', self::PRIVILEGES), $database, $this->account($name));

        $pdo->exec("CREATE USER IF NOT EXISTS {$this->account($user)} IDENTIFIED BY {$password} REQUIRE SSL");
        $pdo->exec("ALTER USER {$this->account($user)} IDENTIFIED BY {$password} REQUIRE SSL");
        $pdo->exec($grant($user));
        $this->line("{$user}: the user of the current version of the secret, its password, TLS required and ".implode(', ', self::PRIVILEGES)." on {$database} (as {$migrations}).");

        if ($previous !== null && $previous !== $user) {
            try {
                $pdo->exec("ALTER USER {$this->account($previous)} REQUIRE SSL");
                $pdo->exec($grant($previous));
                $this->line("{$previous}: the user of the previous version, valid until the next rotation, the same privileges and its own password.");
            } catch (PDOException $exception) {
                // ER_CANNOT_USER: the previous version names a user the database no longer has.
                if (! str_contains($exception->getMessage(), '1396')) {
                    throw $exception;
                }

                $this->line("{$previous}: the user of the previous version no longer exists.");
            }
        }

        // As the identity itself: TLS, and its privileges are those above and nothing more.
        config(['database.connections.'.self::CHECK => [...$settings, 'username' => $user, 'password' => $current['DB_PASSWORD'] ?? '', 'secrets_id' => null]]);

        try {
            $check = DB::connection(self::CHECK);
            $grants = self::firstColumn($check->select('SHOW GRANTS'));
            $cipher = self::firstColumn([$check->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'")], 1)[0] ?? '';
        } finally {
            DB::purge(self::CHECK);
        }

        $expected = [
            "GRANT USAGE ON *.* TO `{$user}`@`%`",
            sprintf('GRANT %s ON `%s`.* TO `%s`@`%%`', implode(', ', self::PRIVILEGES), $database, $user),
        ];

        if ($grants !== $expected) {
            $this->error("The application identity holds more than it should; revoke the rest:\n  ".implode("\n  ", $grants));

            return self::FAILURE;
        }

        $this->info("The application identity {$user} connects".($cipher === '' ? '' : " over TLS ({$cipher})").' and holds '.implode(', ', self::PRIVILEGES)." on {$database}, nothing of its schema.");

        return self::SUCCESS;
    }

    /**
     * A column of each row of a statement such as SHOW GRANTS or SHOW STATUS, as text.
     *
     * @param  array<mixed>  $rows
     * @return list<string>
     */
    public static function firstColumn(array $rows, int $column = 0): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = is_object($row) ? (array_values((array) $row)[$column] ?? null) : null;

            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** 'user'@'%': the identities connect from anywhere the security groups let them. */
    private function account(string $user): string
    {
        return "'{$user}'@'%'";
    }

    private function currentUser(Connection $connection): string
    {
        $row = $connection->selectOne('SELECT CURRENT_USER() AS user');

        return is_object($row) && isset($row->user) && is_string($row->user) ? $row->user : '?';
    }
}
