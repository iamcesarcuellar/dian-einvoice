<?php

namespace App\Support\ApiDocs;

use App\Modules\Developers\Http\Middleware\HandleIdempotency;
use App\Modules\Identity\Http\Middleware\ResolveCurrentCompany;
use App\Support\Observability\LogSchema;
use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * What the middleware of the public API adds to every operation, which Scramble cannot infer from
 * the controllers: the X-Company-Id and Idempotency-Key headers, the X-Correlation-Id a request may
 * send and every response carries, and the 401, 403, 409, 422 and 429 responses of authentication, key
 * levels, idempotency and the rate limit, all problem+json.
 */
final class ApiConventionsExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $middleware = collect($routeInfo->route->gatherMiddleware())->filter(fn ($name): bool => is_string($name));
        $tenant = $middleware->contains('company');
        $idempotent = $middleware->contains('idempotency') && strtoupper($operation->method) === 'POST';

        // description() and required() of Scramble declare no return type, so they are not chained.
        if ($tenant) {
            $company = Parameter::make(ResolveCurrentCompany::HEADER, 'header')->setSchema(ProblemResponses::schema(new StringType));
            $company->description('The company to act on. Required with a session. With an API key, the company is the key\'s, and a value sent must match it.');
            $company->required(false);
            $operation->addParameters([$company]);
        }

        if ($idempotent) {
            $key = Parameter::make(HandleIdempotency::HEADER, 'header')->setSchema(ProblemResponses::schema(new StringType));
            $key->description('Optional. A unique value, such as a UUID, that makes retries safe: for 24 hours, the same key with the same request returns the first response (marked Idempotent-Replayed: true) without running again.');
            $key->required(false);
            $operation->addParameters([$key]);
        }

        $codes = $this->codes($operation);

        if ($middleware->contains(fn (string $name): bool => Str::startsWith($name, 'auth')) && ! in_array(401, $codes, true)) {
            $operation->addResponse(ProblemResponses::make(401, 'Not authenticated: no session and no valid API key'));
        }

        if (($tenant || $middleware->contains(fn (string $name): bool => Str::startsWith($name, 'permission:'))) && ! in_array(403, $codes, true)) {
            $operation->addResponse(ProblemResponses::make(403, 'Forbidden: not a member of the company, a role or an API key level that does not allow it, or another company\'s key'));
        }

        if ($idempotent && ! in_array(409, $codes, true)) {
            $operation->addResponse(ProblemResponses::make(409, 'Conflict with the current state (such as issuing twice), or a request with the same Idempotency-Key still in progress'));
        }

        if ($idempotent && ! in_array(422, $codes, true)) {
            $operation->addResponse(ProblemResponses::make(422, 'Validation failed, or the Idempotency-Key was already used with another request', validation: true));
        }

        // The journey of every request (App\Http\Middleware\TraceRequest): sent, optionally, and always answered.
        $journey = Parameter::make(LogSchema::correlationHeader(), 'header')->setSchema(ProblemResponses::schema((new StringType)->example('01jcz9w6n8s0k3q4r5t6v7w8x9')));
        $journey->description('Optional. The id of the journey this request begins, which ties an integration\'s logs to the platform\'s: a ULID, 26 characters. It is kept when it has that form, and replaced by a new one otherwise. The response carries it in its own X-Correlation-Id, and its problems as correlation_id; the journey\'s events, webhooks and emails carry it too.');
        $journey->required(false);
        $operation->addParameters([$journey]);

        if ($middleware->contains('api-rate-limit')) {
            $operation->addResponse(ProblemResponses::make(429, 'Too many requests for this API key: wait the seconds Retry-After gives')
                ->addHeader('Retry-After', new Header('Seconds until the limit\'s window resets.', schema: ProblemResponses::schema(new IntegerType)))
                ->addHeader('X-RateLimit-Limit', new Header('The key\'s requests per minute.', schema: ProblemResponses::schema(new IntegerType)))
                ->addHeader('X-RateLimit-Remaining', new Header('Requests left in the current window: 0.', schema: ProblemResponses::schema(new IntegerType)))
                ->addHeader('X-RateLimit-Reset', new Header('Unix time when the window resets.', schema: ProblemResponses::schema(new IntegerType))));
        }

        // Every answer, problems included, carries the ids of the request and of its journey.
        foreach ($operation->responses ?? [] as $response) {
            $resolved = $response instanceof Reference ? $response->resolve() : $response;

            if ($resolved instanceof Response) {
                $resolved->addHeader(LogSchema::correlationHeader(), new Header('The request\'s journey id: the one it sent, when it has that form, or a new ULID. Every log line, event, webhook and email of the journey carries it: quote it when asking about a request.', schema: ProblemResponses::schema(new StringType)));
            }
        }
    }

    /**
     * @return list<int>
     */
    private function codes(Operation $operation): array
    {
        $codes = [];

        foreach ($operation->responses ?? [] as $response) {
            $resolved = $response instanceof Reference ? $response->resolve() : $response;

            if ($resolved instanceof Response && $resolved->code !== null) {
                $codes[] = (int) $resolved->code;
            }
        }

        return $codes;
    }
}
