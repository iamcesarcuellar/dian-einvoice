<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SecurityHeaders;
use App\Http\ProblemDetails;
use App\Modules\Developers\Http\Middleware\HandleIdempotency;
use App\Modules\Developers\Http\Middleware\RateLimitApiKeys;
use App\Modules\Developers\Http\Middleware\RejectApiKeys;
use App\Modules\Identity\Http\Middleware\ResolveCurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every answer, errors included: an X-Request-Id that the logs carry too, and the security headers.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        // The web app signs in with Sanctum: a session in an httpOnly cookie plus a CSRF token.
        $middleware->statefulApi();

        // API only: unauthenticated requests get a 401 problem, never a redirect to a login page.
        $middleware->redirectGuestsTo(fn () => null);

        // After authentication, in this order and before route model binding: refuse API keys on the
        // routes of the web app only, resolve the current company (so tenant models are looked up
        // inside it and a record of another company answers 404), count the request against the rate
        // limit of its key, and reserve its Idempotency-Key.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RejectApiKeys::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveCurrentCompany::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RateLimitApiKeys::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: HandleIdempotency::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error, validation included and outside /api/v1 too (an unknown path, a wrong method),
        // is answered as application/problem+json: the backend renders no HTML error pages.
        $exceptions->render(function (Throwable $e) {
            if ($e instanceof HttpResponseException) {
                return null;
            }

            return ProblemDetails::fromThrowable($e);
        });
    })->create();
