<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EdgeClientAddress;
use App\Http\Middleware\SecurityHeaders;
use App\Http\ProblemDetails;
use App\Modules\Developers\Http\Middleware\HandleIdempotency;
use App\Modules\Developers\Http\Middleware\RateLimitApiKeys;
use App\Modules\Developers\Http\Middleware\RejectApiKeys;
use App\Modules\Identity\Http\Middleware\ResolveCurrentCompany;
use App\Support\Health\SchedulerHeartbeat;
use App\Support\Secrets\LoadSecrets;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every answer, errors included: an X-Request-Id that the logs carry too, and the security headers.
        // Behind the edge, the address of the client is the one the edge saw (config/edge.php), before anything
        // counts a request by its address.
        $middleware->prepend([AssignRequestId::class, EdgeClientAddress::class]);
        $middleware->append(SecurityHeaders::class);

        // Of the forwarded headers, only the address of the client and the scheme, and only from the proxies
        // of config/trustedproxy.php (none locally): a client can never choose the host of generated URLs, which
        // behind the edge take APP_URL (AppServiceProvider).
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

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
    })
    ->withSchedule(function (Schedule $schedule): void {
        // The scheduler function runs php artisan schedule:run once a minute. A schedule may fire twice or
        // overlap with the previous run, so each task runs on one process per minute (onOneServer) and
        // never alongside itself (withoutOverlapping), both through the locks of the shared cache, and
        // nothing runs in the background: a function is frozen as soon as it answers. Locally nothing
        // runs the scheduler; the relay process sweeps.
        $schedule->command('outbox:sweep')->everyMinute()->onOneServer()->withoutOverlapping(10);
        $schedule->call(new SchedulerHeartbeat)->name('scheduler-heartbeat')->everyMinute()->onOneServer();
    })->create();

// The emulated cloud and production read the secrets of the application from AWS Secrets Manager
// (APP_SECRETS=secrets-manager) after the environment file and before the configuration, unless the
// configuration is cached with them already (the functions, once per cold start).
$app->afterBootstrapping(LoadEnvironmentVariables::class, fn (Application $app) => LoadSecrets::bootstrap($app));

return $app;
