<?php

namespace App\Http;

use App\Http\Middleware\AssignRequestId;
use App\Support\Observability\Correlation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Error responses in the application/problem+json format (RFC 9457).
 */
final class ProblemDetails
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param  array<string, mixed>  $extensions  Extra members, such as the validation errors.
     * @param  array<mixed>  $headers  As a Symfony response takes them.
     */
    public static function response(int $status, ?string $detail = null, array $extensions = [], array $headers = []): JsonResponse
    {
        $problem = [
            'type' => 'about:blank',
            'title' => self::title($status),
            'status' => $status,
        ];

        if ($detail !== null && $detail !== '') {
            $problem['detail'] = $detail;
        }

        $problem['instance'] = request()->getPathInfo();

        // The ids the logs carry for this request and for its journey, so an answer can be traced without internal
        // details (docs/observability.md).
        $requestId = AssignRequestId::current();

        if ($requestId !== null) {
            $problem['request_id'] = $requestId;
        }

        $correlationId = Correlation::current();

        if ($correlationId !== null) {
            $problem['correlation_id'] = $correlationId;
        }

        return new JsonResponse($problem + $extensions, $status, ['Content-Type' => self::CONTENT_TYPE] + $headers);
    }

    public static function fromThrowable(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::response(422, 'The given data was invalid.', ['errors' => $e->errors()]),
            $e instanceof AuthenticationException => self::unauthenticated($e),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::serverError($e),
        };
    }

    /**
     * 401: the API key was refused (its own message), or no credentials came at all. The challenge
     * names the bearer scheme of API keys; the web app signs in with its session instead.
     */
    private static function unauthenticated(AuthenticationException $e): JsonResponse
    {
        $message = $e->getMessage();
        $detail = $message !== '' && $message !== 'Unauthenticated.'
            ? $message
            : 'Sign in to the web app, or send an API key in the Authorization header: Bearer dei_...';

        return self::response(401, $detail, headers: ['WWW-Authenticate' => 'Bearer realm="dian-einvoice"']);
    }

    private static function fromHttpException(HttpExceptionInterface&Throwable $e): JsonResponse
    {
        $status = $e->getStatusCode();

        $detail = match ($status) {
            404 => 'The requested resource was not found.',
            419 => 'The CSRF token is missing or has expired. Reload the page and try again.',
            default => $e->getMessage(),
        };

        return self::response($status, $detail, headers: $e->getHeaders());
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        if (! config('app.debug')) {
            return self::response(500, 'Something went wrong on our side.');
        }

        return self::response(500, $e->getMessage(), [
            'debug' => ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()],
        ]);
    }

    private static function title(int $status): string
    {
        return match ($status) {
            419 => 'CSRF Token Mismatch',
            default => Response::$statusTexts[$status] ?? 'Error',
        };
    }
}
