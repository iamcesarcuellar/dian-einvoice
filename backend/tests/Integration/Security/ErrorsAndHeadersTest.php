<?php

namespace Tests\Integration\Security;

use App\Http\Middleware\AssignRequestId;
use App\Support\Observability\Correlation;
use App\Support\Observability\LogSchema;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\IntegrationTestCase;

/**
 * What every answer carries, errors included: problem+json without traces or internal details for
 * 404, 405, 419 and 500 (429 in AccountsTest), an X-Request-Id that the logs carry too, and the
 * security headers of resources/http/security-headers.json; HSTS in production. The development tools
 * refuse to run there. The page of the API reference has a policy of its own (EdgeTest).
 */
final class ErrorsAndHeadersTest extends IntegrationTestCase
{
    public function test_errors_are_problems_without_internal_details(): void
    {
        config(['app.debug' => false]);
        Route::get('api/v1/synthetic-failure', fn () => throw new RuntimeException('Synthetic failure at /app/secret/path.php with password hunter2'));
        Route::post('api/v1/synthetic-csrf', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $answers = [
            404 => $this->getJson('/nothing-lives-here'),
            405 => $this->deleteJson('/api/v1/health'),
            419 => $this->postJson('/api/v1/synthetic-csrf'),
            500 => $this->getJson('/api/v1/synthetic-failure'),
        ];

        foreach ($answers as $status => $response) {
            $response->assertStatus($status)->assertHeader('Content-Type', 'application/problem+json');
            $this->assertSame($status, $response->json('status'));
            $this->assertSame($response->headers->get(AssignRequestId::HEADER), $response->json('request_id'));
            // The journey of the request too, as the header answers it (docs/observability.md).
            $this->assertTrue(Correlation::valid($this->text($response->json('correlation_id'))));
            $this->assertSame($response->headers->get(LogSchema::correlationHeader()), $response->json('correlation_id'));
            $this->assertNull($response->json('debug'));
            $this->assertStringNotContainsString('/app/', (string) $response->getContent());
            $this->assertStringNotContainsString('hunter2', (string) $response->getContent());
            $this->assertSecurityHeaders($response, json: true);
        }

        $this->assertSame('Something went wrong on our side.', $answers[500]->json('detail'));
        $this->assertSame('CSRF Token Mismatch', $answers[419]->json('title'));
    }

    public function test_the_request_id_is_in_the_answer_and_in_the_log_of_the_request(): void
    {
        $log = storage_path('logs/request-id-test.log');
        File::delete($log);
        config(['app.debug' => false, 'logging.channels.single.path' => $log]);
        app('log')->forgetChannel('single');
        Route::get('api/v1/synthetic-logged-failure', fn () => throw new RuntimeException('Synthetic failure to log.'));

        try {
            $response = $this->getJson('/api/v1/synthetic-logged-failure')->assertStatus(500);
            $id = (string) $response->headers->get(AssignRequestId::HEADER);

            $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', $id);
            $this->assertStringContainsString('"request_id":"'.$id.'"', File::get($log));
        } finally {
            app('log')->forgetChannel('single');
            File::delete($log);
        }

        // A proxy may send its own id; anything that could break a log line is replaced.
        $this->getJson('/api/v1/health', [AssignRequestId::HEADER => 'proxy-id-12345'])->assertHeader(AssignRequestId::HEADER, 'proxy-id-12345');
        $this->assertNotSame("bad\r\nid", $this->getJson('/api/v1/health', [AssignRequestId::HEADER => "bad\r\nid-0000"])->headers->get(AssignRequestId::HEADER));
    }

    public function test_security_headers_on_data_and_files_and_hsts_in_production(): void
    {
        Route::get('api/v1/synthetic-file', fn () => response('%PDF-1.7', 200, ['Content-Type' => 'application/pdf']));

        $this->assertSecurityHeaders($this->getJson('/api/v1/health')->assertOk(), json: true);
        $this->assertSecurityHeaders($this->get('/api/v1/synthetic-file')->assertOk(), json: false);
        $this->getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');

        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->getJson('/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $this->assertSame($this->declared()['common']['Strict-Transport-Security'], $this->getJson('/api/v1/health')->headers->get('Strict-Transport-Security'));
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_the_development_tools_refuse_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $commands = ['billing:stress' => [], 'dian:chaos' => ['action' => 'start'], 'dian:check' => [], 'delivery:check' => [], 'payments:check' => [], 'api:check' => [], 'ui:check' => []];

            foreach ($commands as $command => $arguments) {
                $this->assertSame(1, Artisan::call($command, $arguments), $command);
                $this->assertStringContainsString('local development tool', Artisan::output(), $command);
            }

            try {
                Artisan::call('db:seed', ['--force' => true]);
                $this->fail('The demo seeder ran in production.');
            } catch (LogicException $refused) {
                $this->assertStringContainsString('local development tool', $refused->getMessage());
            }
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
        }
    }

    /**
     * The headers of an API response, as the single source of truth declares them for its kind of document
     * (resources/http/security-headers.json), and their values as they always were.
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertSecurityHeaders(TestResponse $response, bool $json): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', $json ? "default-src 'none'; frame-ancestors 'none'" : "frame-ancestors 'none'");

        $declared = $this->declared();
        $document = $declared['documents'][$json ? 'api' : 'files'];
        $policy = implode('; ', array_map(fn (string $directive, string $sources): string => "{$directive} {$sources}", array_keys($document['contentSecurityPolicy']), $document['contentSecurityPolicy']));
        $response->assertHeader('Content-Security-Policy', $policy);

        foreach (['X-Content-Type-Options', 'X-Frame-Options'] as $name) {
            $response->assertHeader($name, $declared['common'][$name]);
        }

        foreach ($document['headers'] as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    /**
     * @return array{common: array<string, string>, documents: array<string, array{headers: array<string, string>, contentSecurityPolicy: array<string, string>}>}
     */
    private function declared(): array
    {
        /** @var array{common: array<string, string>, documents: array<string, array{headers: array<string, string>, contentSecurityPolicy: array<string, string>}>} */
        return json_decode((string) file_get_contents(resource_path('http/security-headers.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
