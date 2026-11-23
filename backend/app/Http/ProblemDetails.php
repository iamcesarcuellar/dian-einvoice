<?php

namespace App\Http;

use App\Http\Middleware\AssignRequestId;
use App\Support\Language;
use App\Support\Observability\Correlation;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Error responses in the application/problem+json format (RFC 9457), in the language of the request
 * (App\Http\Middleware\NegotiateLanguage): the title of the status, the detail and the validation messages.
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
            $e instanceof ValidationException => self::response(422, __('problems.invalid'), ['errors' => $e->errors()]),
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
            : __('problems.unauthenticated');

        return self::response(401, $detail, headers: ['WWW-Authenticate' => 'Bearer realm="dian-einvoice"']);
    }

    private static function fromHttpException(HttpExceptionInterface&Throwable $e): JsonResponse
    {
        $status = $e->getStatusCode();

        // The framework's own texts of a wrong method, a body too large or a throttled route stay as they are in
        // English; in Spanish they get a fixed text. Every other message is already in the language of the request.
        $framework = "problems.framework.{$status}";

        $detail = match (true) {
            $status === 404 => __('problems.not_found'),
            $status === 419 => __('problems.csrf'),
            Language::current() !== Language::English && Lang::has($framework) && self::fromFramework($e) => __($framework),
            default => $e->getMessage(),
        };

        return self::response($status, $detail, headers: $e->getHeaders());
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        if (! config('app.debug')) {
            return self::response(500, __('problems.server_error'));
        }

        return self::response(500, $e->getMessage(), [
            'debug' => ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()],
        ]);
    }

    private static function title(int $status): string
    {
        $key = "problems.titles.{$status}";

        return Lang::has($key) ? __($key) : Response::$statusTexts[$status] ?? 'Error';
    }

    /** Whether the framework raised the exception, with a text of its own; this application writes its texts in the request's language. */
    private static function fromFramework(Throwable $e): bool
    {
        return str_starts_with($e->getFile(), base_path('vendor').DIRECTORY_SEPARATOR);
    }
}
