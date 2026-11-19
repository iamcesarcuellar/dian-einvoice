<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web app as a browser runs it, for the tests of sign-in and sessions: JSON requests from the
 * origin the API takes for sessions (Sanctum), the cookies the API set (the session and XSRF-TOKEN)
 * sent back on the next request, and a fresh session store and guards per request, as a real
 * request starts with. Each browser has an address (IP) of its own, so the limits per address of
 * one test never reach another. The session driver must be one that outlives a request (redis).
 */
final class WebBrowser
{
    /** @var array<string, string> Name => value, as the API set them (encrypted). */
    private array $cookies = [];

    /** @var array<string, Cookie> The last Set-Cookie of each name, with its attributes. */
    public array $received = [];

    public function __construct(private readonly TestCase $test, public readonly string $ip) {}

    /** Browsers opened in this run: each one gets the next address. */
    private static int $opened = 0;

    /** A browser at an address no other browser of the run has (10.250.0.0/16). */
    public static function at(TestCase $test): self
    {
        self::$opened++;

        return new self($test, sprintf('10.250.%d.%d', intdiv(self::$opened, 250), self::$opened % 250 + 1));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public function json(string $method, string $uri, array $data = []): TestResponse
    {
        // A real request starts with no session loaded and no user resolved: the session manager, the
        // store the session guard keeps (session.store) and the guards are all made again.
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        Auth::forgetGuards();

        $stateful = config('sanctum.stateful');
        $origin = 'http://'.(is_array($stateful) && is_string($stateful[0] ?? null) ? $stateful[0] : 'localhost:8601');

        $response = $this->test->call($method, $uri, [], $this->cookies, [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_REFERER' => $origin.'/',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $this->ip,
        ], $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));

        foreach ($response->headers->getCookies() as $cookie) {
            $this->received[$cookie->getName()] = $cookie;

            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }

    /** The value of a cookie the browser holds now. */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** Replaces a cookie, as if the browser had kept an older one. */
    public function withCookie(string $name, ?string $value): self
    {
        if ($value === null) {
            unset($this->cookies[$name]);
        } else {
            $this->cookies[$name] = $value;
        }

        return $this;
    }
}
