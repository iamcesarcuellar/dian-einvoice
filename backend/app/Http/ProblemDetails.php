<?php

namespace App\Http;

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
     * @param  array<string, string|int>  $headers
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

        return new JsonResponse($problem + $extensions, $status, ['Content-Type' => self::CONTENT_TYPE] + $headers);
    }

    public static function fromThrowable(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ValidationException => self::response(422, 'The given data was invalid.', ['errors' => $e->errors()]),
            $e instanceof AuthenticationException => self::response(401, 'Sign in to continue.'),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e),
            default => self::serverError($e),
        };
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
