<?php

namespace Tests\Integration\Security;

use App\Http\Middleware\EdgeClientAddress;
use App\Http\PublicOrigin;
use App\Support\ApiDocs\SecureApiReference;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\IntegrationTestCase;

/**
 * The application behind the edge (Infrastructure task 4): every route of the application is one the edge sends
 * to the API, never to the bucket of the web app (resources/http/paths.json); the address of the client is the entry
 * the edge appended to X-Forwarded-For, whether API Gateway appended the address of its peer after it or not, and
 * whatever headers the client sent; the URLs the application generates take the public origin; the page of the API reference pins its renderer and allows its inline scripts by hash; and CORS is not
 * offered where the web app, on the same origin, needs none.
 */
final class EdgeTest extends IntegrationTestCase
{
    /** Routes that exist only with the development tools of Scramble, which the functions turn off (SCRAMBLE_DEV_TOOLS). */
    private const array DEVELOPMENT_ROUTES = ['scramble.dev-tools.asset'];

    public function test_every_route_of_the_application_is_one_the_edge_sends_to_the_api(): void
    {
        $patterns = $this->paths();
        $this->assertNotEmpty($patterns);

        foreach ($patterns as $pattern) {
            // CloudFront path patterns: from the root, * for any characters, ? for one.
            $this->assertMatchesRegularExpression('#^/[A-Za-z0-9_./*?-]+$#', $pattern);
        }

        $routes = collect(Router::getRoutes()->getRoutes())->reject(fn (Route $route): bool => in_array($route->getName(), self::DEVELOPMENT_ROUTES, true));
        $this->assertGreaterThan(100, $routes->count());
        $outside = $routes->map(fn (Route $route): string => '/'.ltrim($route->uri(), '/'))->reject(fn (string $path): bool => collect($patterns)->contains(fn (string $pattern): bool => $this->patternMatches($pattern, $path)));

        $this->assertSame([], $outside->values()->all(), 'Routes the edge would send to the bucket of the web app.');

        // Paths of the web app never reach the API.
        foreach (['/', '/invoices', '/invoices/01JABCDEF', '/settings/developers', '/assets/index-abc12345.js'] as $path) {
            $this->assertFalse(collect($patterns)->contains(fn (string $pattern): bool => $this->patternMatches($pattern, $path)), $path);
        }
    }

    public function test_the_sign_in_points_the_firewall_limits_are_post_routes_with_a_rate_limit_of_their_own(): void
    {
        $points = $this->paths('authentication');
        $this->assertNotEmpty($points);

        foreach ($points as $point) {
            $route = collect(Router::getRoutes()->getRoutes())->first(fn (Route $route): bool => '/'.$route->uri() === $point && in_array('POST', $route->methods(), true));
            $this->assertInstanceOf(Route::class, $route, "{$point} is a POST route of the application");
            $this->assertNotEmpty(array_filter($route->gatherMiddleware(), fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle:')), "{$point} has a rate limit of its own");
        }
    }

    public function test_behind_the_edge_the_address_of_the_client_is_the_entry_the_edge_appended_to_x_forwarded_for(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1', 'edge.behind' => true]);

        // CloudFront appends the address of the client; API Gateway, whose peer is the edge location, may append that
        // address after it (as AWS documents) or not. The answer is the same both ways.
        foreach (['API Gateway appends its peer' => ['130.176.0.5', ', 130.176.0.5', '2600:9000::1', ', 2600:9000::1'], 'API Gateway appends nothing' => ['130.176.0.5', '', '2600:9000::1', '']] as $case => [$peer, $appended, $peer6, $appended6]) {
            $this->assertSame('198.51.100.10', $this->clientAddress(['X-Forwarded-For' => "198.51.100.10{$appended}"], $peer), $case);
            // Whatever the client wrote, in either header, counts for nothing: one entry or many, an address or not.
            $this->assertSame('198.51.100.10', $this->clientAddress(['X-Forwarded-For' => "203.0.113.7, 198.51.100.10{$appended}", 'CloudFront-Viewer-Address' => '203.0.113.7:443'], $peer), $case);
            $this->assertSame('198.51.100.10', $this->clientAddress(['X-Forwarded-For' => "203.0.113.7, 203.0.113.8, not-an-address, 198.51.100.10{$appended}"], $peer), $case);
            $this->assertSame('2001:db8::7', $this->clientAddress(['X-Forwarded-For' => "203.0.113.7, 2001:db8::7{$appended6}"], $peer6), $case);
            // A client that writes the address of the edge location itself gains nothing: CloudFront appends the
            // client's after it.
            $this->assertSame('198.51.100.10', $this->clientAddress(['X-Forwarded-For' => "130.176.0.5, 198.51.100.10{$appended}"], $peer), $case);
            // Without the entry of the edge, or with one that is not an address, no forwarded address is believed.
            $this->assertSame('127.0.0.1', $this->clientAddress(['X-Forwarded-For' => "203.0.113.7, not-an-address{$appended}"], $peer), $case);
            $this->assertSame('127.0.0.1', $this->clientAddress(['CloudFront-Viewer-Address' => '203.0.113.7:443'], $peer), $case);
        }

        // Only API Gateway's entry, without one of CloudFront: nothing is left to believe.
        $this->assertSame('127.0.0.1', $this->clientAddress(['X-Forwarded-For' => '130.176.0.5', 'CloudFront-Viewer-Address' => '203.0.113.7:443'], '130.176.0.5'));
        // The addresses are compared, and answered, in one spelling: IPv6 in any notation or case, IPv4 mapped into IPv6.
        $this->assertSame('2001:db8::7', $this->clientAddress(['X-Forwarded-For' => '203.0.113.7, 2001:DB8:0:0::7, 2600:9000:0:0:0:0:0:1'], '2600:9000::1'));
        $this->assertSame('198.51.100.10', $this->clientAddress(['X-Forwarded-For' => '203.0.113.7, 198.51.100.10, ::ffff:130.176.0.5'], '130.176.0.5'));
        $this->assertSame('198.51.100.10', $this->clientAddress(['X-Forwarded-For' => '203.0.113.7, ::FFFF:198.51.100.10'], '2600:9000::1'));
        // Without the peer API Gateway reports (no request context, or one that is not an address), nothing is believed.
        $this->assertSame('127.0.0.1', $this->clientAddress(['X-Forwarded-For' => '198.51.100.10, 130.176.0.5'], null));
        $this->assertSame('127.0.0.1', $this->clientAddress(['X-Forwarded-For' => '198.51.100.10, 130.176.0.5'], 'test-invoke-source-ip'));

        // In the emulated cloud: its CloudFront appends the address of the client, its API Gateway nothing, and it
        // reports 127.0.0.1 as the peer of every request, which no entry the edge appends is.
        $this->assertSame('172.24.0.6', $this->clientAddress(['X-Forwarded-For' => '203.0.113.7, 198.51.100.9, 172.24.0.6', 'CloudFront-Viewer-Address' => '203.0.113.7:443'], '127.0.0.1'));
        $this->assertSame('127.0.0.1', $this->clientAddress([], '127.0.0.1'));

        // Without the edge, the address the proxy in front saw, as before.
        config(['edge.behind' => false]);
        $this->assertSame('130.176.0.5', $this->clientAddress(['X-Forwarded-For' => '198.51.100.10, 130.176.0.5', 'CloudFront-Viewer-Address' => '203.0.113.7:443'], '130.176.0.5'));
    }

    public function test_behind_the_edge_the_rate_limit_of_the_sign_in_counts_the_address_the_edge_saw(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1', 'edge.behind' => true]);

        foreach (['130.176.0.5' => ', 130.176.0.5', 'no entry of API Gateway' => ''] as $appended) {
            $seen = '198.51.100.'.random_int(20, 250);
            $spoofed = ['203.0.113.'.random_int(20, 120), '203.0.113.'.random_int(121, 250)];

            foreach ([$seen, ...$spoofed] as $address) {
                RateLimiter::clear(md5('loginlogin|'.$address));
            }

            // A client that writes addresses of its own in X-Forwarded-For and in CloudFront-Viewer-Address.
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'LAMBDA_REQUEST_CONTEXT' => json_encode(['http' => ['sourceIp' => '130.176.0.5']], JSON_THROW_ON_ERROR)])
                ->withHeaders(['X-Forwarded-For' => "{$spoofed[0]}, {$seen}{$appended}", 'CloudFront-Viewer-Address' => "{$spoofed[1]}:51000", 'Origin' => 'http://localhost:8601', 'Referer' => 'http://localhost:8601/login'])
                ->postJson('/api/v1/auth/login', ['email' => 'nobody-'.Str::lower(Str::random(8)).'@example.com', 'password' => 'wrong-password-123'])
                ->assertStatus(422);

            $this->assertEquals(1, RateLimiter::attempts(md5('loginlogin|'.$seen)), 'The sign-in limit counts the address the edge saw.');

            foreach ($spoofed as $address) {
                $this->assertEquals(0, RateLimiter::attempts(md5('loginlogin|'.$address)), "An address the client wrote ({$address}) counts nothing.");
            }
        }
    }

    public function test_behind_the_edge_generated_urls_take_the_public_origin(): void
    {
        PublicOrigin::apply('https://app.dian-einvoice.example/');
        $this->app->instance('request', Request::create('https://abc123.execute-api.us-east-1.amazonaws.com/api/v1/contacts?page=2'));

        $this->assertSame('https://app.dian-einvoice.example/api/v1/contacts', url('api/v1/contacts'));
        $this->assertSame('https://app.dian-einvoice.example/api/v1/contacts', Paginator::resolveCurrentPath());
        $this->assertSame('https://app.dian-einvoice.example/docs/api.json', route('scramble.docs.document'));
    }

    public function test_the_page_of_the_api_reference_pins_its_renderer_and_allows_its_inline_scripts_by_hash(): void
    {
        $response = $this->get('/docs/api')->assertOk();
        $html = (string) $response->getContent();
        $policy = (string) $response->headers->get('Content-Security-Policy');
        /** @var array<string, string> $integrity */
        $integrity = config('headers.documents.apiReference.subresourceIntegrity');
        $this->assertCount(2, $integrity);

        foreach ($integrity as $url => $hash) {
            $this->assertMatchesRegularExpression('/^sha384-[A-Za-z0-9+\/]{64}$/', $hash);
            $this->assertStringContainsString('"'.$url.'" integrity="'.$hash.'" crossorigin="anonymous"', $html);
            $this->assertStringContainsString($url, $policy);
        }

        $directives = collect(explode(';', $policy))->mapWithKeys(fn (string $directive): array => [Str::before(trim($directive), ' ') => trim($directive)]);
        $scripts = (string) $directives->get('script-src');
        $hashes = SecureApiReference::inlineScriptHashes($html);
        $this->assertGreaterThanOrEqual(2, count($hashes), 'The interceptor of Scramble and the document of the API.');

        foreach ($hashes as $hash) {
            $this->assertStringContainsString($hash, $scripts);
        }

        $this->assertStringNotContainsString("'unsafe-inline'", $scripts);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
        $this->assertSame("default-src 'none'", $directives->get('default-src'));
        $this->assertSame("frame-ancestors 'none'", $directives->get('frame-ancestors'));
        $response->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');

        // The document of the API itself is JSON and keeps the policy that allows nothing.
        $this->getJson('/docs/api.json')->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_cors_is_offered_to_api_keys_only_never_with_credentials(): void
    {
        $preflight = ['Origin' => 'https://integrator.example', 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'authorization'];

        $api = $this->withHeaders($preflight)->options('/api/v1/invoices');
        $api->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertNull($api->headers->get('Access-Control-Allow-Credentials'));

        // The CSRF cookie of the session belongs to the web app, which calls it on its own origin.
        $this->assertNull($this->withHeaders($preflight)->options('/sanctum/csrf-cookie')->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * The address the application counts a request by, after the edge and the Bref runtime: PHP-FPM sees the
     * request come from 127.0.0.1, and the runtime passes the request context of API Gateway, with the source address
     * it reports (its peer), in LAMBDA_REQUEST_CONTEXT.
     *
     * @param  array<string, string>  $headers
     */
    private function clientAddress(array $headers, ?string $peer): string
    {
        $server = ['REMOTE_ADDR' => '127.0.0.1'];

        if ($peer !== null) {
            $server['LAMBDA_REQUEST_CONTEXT'] = json_encode(['http' => ['method' => 'GET', 'sourceIp' => $peer]], JSON_THROW_ON_ERROR);
        }

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('/api/v1/health', server: $server);
        $ip = null;

        app(EdgeClientAddress::class)->handle($request, function (Request $request) use (&$ip): Response {
            $response = app(TrustProxies::class)->handle($request, function (Request $request) use (&$ip): Response {
                $ip = $request->ip();

                return response('');
            });
            $this->assertInstanceOf(Response::class, $response);

            return $response;
        });

        return (string) $ip;
    }

    /** Whether a CloudFront path pattern matches a path: * any characters, / included; ? one character. */
    private function patternMatches(string $pattern, string $path): bool
    {
        $regex = '#^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '#')).'$#';

        return preg_match($regex, $path) === 1;
    }

    /**
     * @return list<string>
     */
    private function paths(string $list = 'api'): array
    {
        $paths = json_decode((string) file_get_contents(resource_path('http/paths.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($paths);
        $this->assertIsArray($paths[$list] ?? null);

        return array_values(array_map(fn (mixed $path): string => is_string($path) ? $path : '', $paths[$list]));
    }
}
