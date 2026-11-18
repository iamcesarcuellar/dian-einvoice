<?php

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Type\Literal\LiteralIntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * Documents every exception the API turns into an answer as the problem+json it really is, instead
 * of the default {message} shape: registered after the defaults, so it takes precedence.
 */
final class ProblemDetailsExceptionExtension extends ExceptionToResponseExtension
{
    /** Exceptions with a fixed status, as Laravel prepares them before ProblemDetails renders them. */
    private const array STATUSES = [
        ValidationException::class => 422,
        AuthenticationException::class => 401,
        AuthorizationException::class => 403,
        ModelNotFoundException::class => 404,
        RecordsNotFoundException::class => 404,
        AccessDeniedHttpException::class => 403,
        BadRequestHttpException::class => 400,
        ConflictHttpException::class => 409,
        NotFoundHttpException::class => 404,
        ServiceUnavailableHttpException::class => 503,
        TooManyRequestsHttpException::class => 429,
        UnauthorizedHttpException::class => 401,
        UnprocessableEntityHttpException::class => 422,
    ];

    private const array DESCRIPTIONS = [
        400 => 'Bad request',
        401 => 'Not authenticated: no session and no valid API key',
        403 => 'Forbidden: the role, or the API key, does not allow it',
        404 => 'Not found in the current company',
        409 => 'Conflict with the current state',
        422 => 'Validation failed',
        429 => 'Too many requests',
        503 => 'A local service is not answering',
    ];

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(Throwable::class) && $this->status($type) !== null;
    }

    public function toResponse(Type $type)
    {
        /** @var ObjectType $type */
        $status = (int) $this->status($type);

        return ProblemResponses::make($status, self::DESCRIPTIONS[$status] ?? 'Error', $type->isInstanceOf(ValidationException::class));
    }

    /** Exceptions with a fixed status share one response component per class. */
    public function reference(ObjectType $type): ?Reference
    {
        foreach (array_keys(self::STATUSES) as $class) {
            if ($type->name === $class) {
                return new Reference('responses', 'Problem'.class_basename($class), $this->components);
            }
        }

        return null;
    }

    private function status(ObjectType $type): ?int
    {
        $code = count($type->templateTypes ?? []) > 3 ? ($type->templateTypes[7] ?? null) : ($type->templateTypes[0] ?? null);

        if ($type->isInstanceOf(HttpException::class) && $code instanceof LiteralIntegerType) {
            return $code->value;
        }

        foreach (self::STATUSES as $class => $status) {
            if ($type->isInstanceOf($class)) {
                return $status;
            }
        }

        return null;
    }
}
