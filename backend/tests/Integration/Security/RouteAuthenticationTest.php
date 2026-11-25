<?php

namespace Tests\Integration\Security;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\IntegrationTestCase;

/**
 * Deny by default: every route of the backend, called without credentials, answers 401, except the
 * short list below. A new route that forgets its authentication fails here.
 */
final class RouteAuthenticationTest extends IntegrationTestCase
{
    /** Routes anyone may call, by name, each with why. */
    private const array PUBLIC = [
        'health' => 'the healthcheck of the stack',
        'auth.register' => 'sign-up, limited per address',
        'auth.login' => 'sign-in, limited per address and per email',
        'auth.password.email' => 'the reset link, limited per address, same answer for any email',
        'auth.password.reset' => 'the new password, with the token of the link',
        'invitations.show' => 'the page of an invitation link: the token is the credential',
        'invitations.accept' => 'accepting it: the token is the credential',
        'sanctum.csrf-cookie' => 'the CSRF cookie the web app asks for before signing in',
        'scramble.docs.ui' => 'the public API reference',
        'scramble.docs.document' => 'the OpenAPI document of the reference',
        'scramble.docs.es.ui' => 'the public API reference, in Spanish',
        'scramble.docs.es.document' => 'the OpenAPI document of the reference in Spanish',
        'scramble.dev-tools.asset' => 'a script of the reference page',
    ];

    public function test_every_route_but_the_public_ones_needs_authentication(): void
    {
        $open = [];
        $names = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = (string) $route->getName();
            $names[] = $name;

            if (array_key_exists($name, self::PUBLIC)) {
                continue;
            }

            $method = array_values(array_diff($route->methods(), ['HEAD']))[0] ?? 'GET';
            $uri = '/'.ltrim((string) preg_replace('/\{[^}]+\}/', '01jaaaaaaaaaaaaaaaaaaaaaaa', $route->uri()), '/');
            $status = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json'])->status();

            if ($status !== 401) {
                $open[] = "{$method} {$uri} ({$name}) answered {$status}";
            }
        }

        $this->assertSame([], $open, 'Routes without authentication that are not in the public list.');
        $this->assertSame([], array_values(array_diff(array_keys(self::PUBLIC), $names)), 'Public routes that do not exist any more.');
        $this->assertGreaterThan(100, count($names));
    }
}
