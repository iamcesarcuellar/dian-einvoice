<?php

namespace Tests\Unit\Support;

use Pdo\Mysql;
use PHPUnit\Framework\TestCase;

/**
 * The MySQL connection of config/database.php: locally as the framework defines it; in the functions every
 * connection encrypted with the bundle of the RDS certificate authorities of the image, and in production the
 * certificate of the server verified (DB_SSL_CA, DB_SSL_VERIFY_SERVER_CERT in infra/functions/serverless.yml);
 * and the secret the credentials of the process come from, which its connector reads again when refused.
 */
final class DatabaseConnectionTest extends TestCase
{
    public function test_the_functions_encrypt_every_connection_and_production_verifies_the_server(): void
    {
        $bundle = '/opt/rds/global-bundle.pem';

        $this->assertSame([], $this->mysql([])['options'], 'locally, as the framework defines it');
        $this->assertSame([Mysql::ATTR_SSL_CA => $bundle, Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true], $this->mysql(['DB_SSL_CA' => $bundle, 'DB_SSL_VERIFY_SERVER_CERT' => 'true'])['options']);
        $this->assertSame([Mysql::ATTR_SSL_CA => $bundle, Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true], $this->mysql(['DB_SSL_CA' => $bundle])['options'], 'verified unless told otherwise');
        $this->assertSame([Mysql::ATTR_SSL_CA => $bundle, Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false], $this->mysql(['DB_SSL_CA' => $bundle, 'DB_SSL_VERIFY_SERVER_CERT' => 'false'])['options'], 'the emulated cloud: encrypted, not verified');

        $this->assertNull($this->mysql([])['secrets_id']);
        $this->assertSame('arn:aws:secretsmanager:us-east-1:111111111111:secret:dian-einvoice/prod/database/application', $this->mysql(['DB_SECRETS_ID' => 'arn:aws:secretsmanager:us-east-1:111111111111:secret:dian-einvoice/prod/database/application'])['secrets_id']);
    }

    /**
     * The MySQL connection config/database.php builds with these environment settings.
     *
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private function mysql(array $environment): array
    {
        $names = ['DB_SSL_CA', 'DB_SSL_VERIFY_SERVER_CERT', 'MYSQL_ATTR_SSL_CA', 'DB_SECRETS_ID'];

        foreach ($names as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);
        }

        foreach ($environment as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            $config = require dirname(__DIR__, 3).'/config/database.php';
        } finally {
            foreach ($names as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            }
        }

        $this->assertIsArray($config);
        $this->assertIsArray($config['connections'] ?? null);
        $mysql = $config['connections']['mysql'] ?? null;
        $this->assertIsArray($mysql);

        /** @var array<string, mixed> $mysql */
        return $mysql;
    }
}
