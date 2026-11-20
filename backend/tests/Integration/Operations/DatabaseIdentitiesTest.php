<?php

namespace Tests\Integration\Operations;

use App\Support\Database\ApplicationIdentityCommand;
use App\Support\Database\RefreshingMySqlConnector;
use App\Support\Secrets\DatabaseCredentials;
use App\Support\Secrets\SecretsManagerSecrets;
use Aws\CommandInterface;
use Aws\Result;
use Aws\Sdk;
use Aws\SecretsManager\Exception\SecretsManagerException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Pdo\Mysql;
use Tests\IntegrationTestCase;
use Throwable;

/**
 * The two identities of the database of the functions, against the real MySQL, with Secrets Manager answering
 * from memory: the connector that reads the secret of a function again, once, when the database refuses its
 * password, as after a rotation; and php artisan db:application-identity, which the console function runs with
 * the migrations identity to create the application identity, which reads and writes data and can change
 * nothing of the schema. make cloud-check does both in the emulated cloud, with the functions warm.
 */
final class DatabaseIdentitiesTest extends IntegrationTestCase
{
    private const string SECRET = 'arn:aws:secretsmanager:us-east-1:000000000000:secret:dian-einvoice/test/database/application';

    /** The migrations identity of these tests (tests/database.sql). */
    private const array MIGRATIONS = ['username' => 'dian_test_migrations', 'password' => 'dian-test-migrations'];

    private const string APPLICATION = 'dian_test_app';

    /** @var array<string, string|null> What Secrets Manager answers, by staging label (AWSCURRENT, AWSPREVIOUS). */
    private array $versions = [];

    private int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Sdk::class, new Sdk([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => function (CommandInterface $command): PromiseInterface {
                $this->reads++;
                $stage = $command['VersionStage'] ?? 'AWSCURRENT';
                $stage = is_string($stage) ? $stage : 'AWSCURRENT';
                $value = $this->versions[$stage] ?? null;

                return $value === null
                    ? Create::rejectionFor(new SecretsManagerException("No version {$stage}.", $command, ['code' => 'ResourceNotFoundException']))
                    : Create::promiseFor(new Result(['SecretString' => $value]));
            },
        ]));
        $this->credentials()->forget(self::SECRET);
    }

    protected function tearDown(): void
    {
        $this->credentials()->forget(self::SECRET);

        try {
            $this->asMigrations()->unprepared(sprintf("DROP USER IF EXISTS '%s'@'%%', '%s_clone'@'%%'", self::APPLICATION, self::APPLICATION));
        } finally {
            DB::purge('test-migrations');
            parent::tearDown();
        }
    }

    public function test_a_connection_whose_password_the_database_refuses_reads_its_secret_again_and_retries_once(): void
    {
        $mysql = (array) config('database.connections.mysql');
        $this->current($this->text($mysql['username']), $this->text($mysql['password']));

        // A warm function: the password of its cold start, which a rotation has replaced since.
        config(['database.connections.rotated' => [...$mysql, 'password' => 'the-password-before-the-rotation', 'secrets_id' => self::SECRET]]);
        $row = DB::connection('rotated')->selectOne('SELECT CURRENT_USER() AS user');
        $this->assertIsObject($row);
        $this->assertSame("{$mysql['username']}@%", $row->user ?? null);
        $this->assertSame(1, $this->reads, 'the secret was read again once');

        // The rest of the execution environment uses what it read, without reading again.
        $file = $this->credentials()->file(self::SECRET);
        $this->assertFileExists($file);
        $this->assertSame('600', substr(sprintf('%o', fileperms($file)), -3), 'only this user reads the credentials');
        DB::purge('rotated');
        DB::connection('rotated')->select('SELECT 1');
        $this->assertSame(1, $this->reads);

        // A second refusal is not retried again: the connection fails after one more read, and Laravel's own
        // reconnection on this error reads nothing more, so soon after a read.
        $this->credentials()->forget(self::SECRET);
        $this->current($this->text($mysql['username']), 'still-not-the-password');
        DB::purge('rotated');
        $this->assertRefused(fn () => DB::connection('rotated')->select('SELECT 1'));
        $this->assertSame(2, $this->reads);
        DB::purge('rotated');
        $this->assertRefused(fn () => DB::connection('rotated')->select('SELECT 1'));
        $this->assertSame(2, $this->reads, 'a password that keeps failing does not read the secret on every connection');

        // Without a secret, a refused password fails as it always did; another error reads nothing either.
        $this->credentials()->forget(self::SECRET);
        config(['database.connections.plain' => [...$mysql, 'password' => 'the-password-before-the-rotation']]);
        $this->assertRefused(fn () => DB::connection('plain')->select('SELECT 1'));
        config(['database.connections.elsewhere' => [...$mysql, 'database' => 'dian_einvoice_test_missing', 'secrets_id' => self::SECRET]]);

        try {
            DB::connection('elsewhere')->select('SELECT 1');
            $this->fail('A database the user may not use refuses the connection.');
        } catch (QueryException $exception) {
            $this->assertFalse(RefreshingMySqlConnector::refused($exception->getPrevious() ?? $exception));
        }

        $this->assertSame(2, $this->reads);
    }

    public function test_the_application_identity_reads_and_writes_data_and_changes_nothing_of_the_schema(): void
    {
        $first = Str::password(24, symbols: false);
        $this->current(self::APPLICATION, $first);
        $this->asMigrations();
        config(['database.default' => 'test-migrations', 'database.application_identity.secret' => self::SECRET]);

        $code = Artisan::call('db:application-identity');
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('holds SELECT, INSERT, UPDATE, DELETE on dian_einvoice_test, nothing of its schema', $output);

        // It reads and writes data...
        $application = $this->as(self::APPLICATION, $first);
        $this->assertIsNumeric($application->scalar('SELECT COUNT(*) FROM migrations'));
        $id = (int) $application->table('migrations')->insertGetId(['migration' => 'identity-probe', 'batch' => 0]);
        $application->table('migrations')->where('id', $id)->update(['batch' => 1]);
        $application->table('migrations')->where('id', $id)->delete();

        // ...and cannot change the schema: ER_TABLEACCESS_DENIED_ERROR for each statement.
        foreach (['CREATE TABLE identity_probe (id INT)', 'ALTER TABLE migrations ADD COLUMN identity_probe INT', 'DROP TABLE migrations', 'CREATE INDEX identity_probe ON migrations (batch)', 'TRUNCATE TABLE migrations'] as $statement) {
            try {
                $application->unprepared($statement);
                $this->fail("The application identity ran {$statement}.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('1142', $exception->getMessage(), $statement);
            }
        }

        // TLS is required: without it the database refuses the identity.
        DB::purge('as-application');
        config(['database.connections.as-application.options' => []]);
        $this->assertRefused(fn () => DB::connection('as-application')->select('SELECT 1'));

        // Run again after a rotation: the user of the new version gets its password, the user of the previous
        // version (alternating users) keeps its own and gets the same privileges.
        $this->asMigrations()->unprepared(sprintf("CREATE USER '%s_clone'@'%%' IDENTIFIED BY 'the-clone-password' REQUIRE SSL", self::APPLICATION));
        $second = Str::password(24, symbols: false);
        $this->current(self::APPLICATION, $second);
        $this->versions['AWSPREVIOUS'] = (string) json_encode(['username' => self::APPLICATION.'_clone', 'password' => 'the-clone-password']);
        $this->assertSame(0, Artisan::call('db:application-identity'), Artisan::output());
        $this->assertRefused(fn () => $this->as(self::APPLICATION, $first)->select('SELECT 1'));
        $this->as(self::APPLICATION, $second)->select('SELECT 1');
        $this->assertContains(
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `dian_einvoice_test`.* TO `dian_test_app_clone`@`%`',
            ApplicationIdentityCommand::firstColumn($this->as(self::APPLICATION.'_clone', 'the-clone-password')->select('SHOW GRANTS')),
        );

        // A previous version that names no user of the database (it held something else) counts as none.
        $this->versions['AWSPREVIOUS'] = 'a value that is not a database secret';
        $this->assertSame(0, Artisan::call('db:application-identity'), Artisan::output());

        // Outside the functions the command has no secret to read and changes nothing.
        config(['database.application_identity.secret' => null]);
        $this->assertSame(1, Artisan::call('db:application-identity'));
    }

    /** The current version of the secret of the application identity. */
    private function current(string $username, string $password): void
    {
        $this->versions['AWSCURRENT'] = (string) json_encode(['username' => $username, 'password' => $password, 'dbname' => 'dian_einvoice_test']);
    }

    /** The connection of the migrations identity of these tests, over TLS. */
    private function asMigrations(): Connection
    {
        config(['database.connections.test-migrations' => [...(array) config('database.connections.mysql'), ...self::MIGRATIONS, 'options' => $this->tls()]]);

        return DB::connection('test-migrations');
    }

    /** A connection as a user, over TLS. */
    private function as(string $username, string $password): Connection
    {
        DB::purge('as-application');
        config(['database.connections.as-application' => [...(array) config('database.connections.mysql'), 'username' => $username, 'password' => $password, 'options' => $this->tls()]]);

        return DB::connection('as-application');
    }

    /**
     * TLS without verification: the MySQL of compose signs its own certificate, for another name than db.
     *
     * @return array<int, mixed>
     */
    private function tls(): array
    {
        return [Mysql::ATTR_SSL_CA => '/etc/ssl/certs/ca-certificates.crt', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false];
    }

    private function credentials(): DatabaseCredentials
    {
        return new DatabaseCredentials(fn (): SecretsManagerSecrets => throw new LogicException('Only the path of the file is used here.'));
    }

    /** The callback fails because the database refused the user or its password. */
    private function assertRefused(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            $this->assertTrue(RefreshingMySqlConnector::refused($exception instanceof QueryException ? ($exception->getPrevious() ?? $exception) : $exception), $exception->getMessage());

            return;
        }

        $this->fail('The database took the credentials.');
    }
}
