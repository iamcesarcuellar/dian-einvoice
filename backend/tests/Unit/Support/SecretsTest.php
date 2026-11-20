<?php

namespace Tests\Unit\Support;

use App\Support\Secrets\LoadSecrets;
use App\Support\Secrets\SecretsManagerSecrets;
use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The secrets of the functions: the secret of a managed database in the format of Amazon RDS
 * (Infrastructure task 3), and the once-per-cold-start reading, which a cached configuration stands for.
 */
final class SecretsTest extends TestCase
{
    public function test_the_secret_of_a_managed_database_becomes_the_settings_of_the_database(): void
    {
        $answers = [
            '{"engine":"mysql","host":"db.cluster-synthetic.us-east-1.rds.amazonaws.com","port":3306,"username":"app","password":"synthetic-secret","dbname":"dian_einvoice","dbInstanceIdentifier":"synthetic"}',
            '{"username":"app","password":"synthetic-secret"}',
            '{"username":"app"}',
        ];
        $secrets = new SecretsManagerSecrets(new SecretsManagerClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => function () use (&$answers): PromiseInterface {
                return Create::promiseFor(new Result(['SecretString' => array_shift($answers)]));
            },
        ]));

        $this->assertSame([
            'DB_USERNAME' => 'app',
            'DB_PASSWORD' => 'synthetic-secret',
            'DB_HOST' => 'db.cluster-synthetic.us-east-1.rds.amazonaws.com',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'dian_einvoice',
        ], $secrets->readDatabase('dian-einvoice/prod/database'));

        // A master secret managed by RDS holds the credentials only: the address comes from the parameters.
        $this->assertSame(['DB_USERNAME' => 'app', 'DB_PASSWORD' => 'synthetic-secret'], $secrets->readDatabase('dian-einvoice/prod/database'));

        $this->expectException(RuntimeException::class);
        $secrets->readDatabase('dian-einvoice/prod/database');
    }

    public function test_nothing_is_read_while_the_configuration_is_cached_with_the_secrets(): void
    {
        $app = $this->createStub(CachesConfiguration::class);
        $app->method('configurationIsCached')->willReturn(true);

        // The source is set and no secret is named: reading would fail, and a cached configuration reads nothing.
        $this->withSource(fn () => LoadSecrets::bootstrap($app));
        $this->expectNotToPerformAssertions();
    }

    public function test_without_a_cached_configuration_the_secret_must_be_named(): void
    {
        $this->expectException(RuntimeException::class);
        $this->withSource(fn () => LoadSecrets::bootstrap(null));
    }

    private function withSource(callable $callback): void
    {
        $_SERVER['APP_SECRETS'] = $_ENV['APP_SECRETS'] = LoadSecrets::SOURCE;
        putenv('APP_SECRETS='.LoadSecrets::SOURCE);

        try {
            $callback();
        } finally {
            unset($_SERVER['APP_SECRETS'], $_ENV['APP_SECRETS']);
            putenv('APP_SECRETS');
        }
    }
}
