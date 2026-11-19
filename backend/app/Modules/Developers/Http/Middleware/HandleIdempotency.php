<?php

namespace App\Modules\Developers\Http\Middleware;

use App\Http\ProblemDetails;
use App\Modules\Developers\Idempotency\IdempotencyFingerprint;
use App\Modules\Developers\Idempotency\IdempotencyStore;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use LogicException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Throwable;

/**
 * Idempotency-Key on any POST, optional, per company. The first request reserves the key, runs and
 * keeps its answer (status and body) for 24 hours with a fingerprint of the request: method, path
 * and body (JSON compared by content, not by spacing or key order). Then:
 *
 * - the same key with the same request gets the kept answer, with Idempotent-Replayed: true, and
 *   nothing runs again;
 * - the same key with another request answers 422;
 * - the same key while the first request is still running answers 409.
 *
 * A 5xx answer is not kept: the key is freed so the request can be tried again. Alias "idempotency".
 */
final class HandleIdempotency
{
    public const string HEADER = 'Idempotency-Key';

    public const string REPLAYED = 'Idempotent-Replayed';

    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->method() !== 'POST' || ! $request->headers->has(self::HEADER)) {
            return $next($request);
        }

        $key = trim((string) $request->headers->get(self::HEADER));

        if ($key === '' || strlen($key) > 255 || preg_match('/[^\x21-\x7E]/', $key) === 1) {
            throw new BadRequestHttpException('Idempotency-Key must be 1 to 255 visible ASCII characters, such as a UUID.');
        }

        [$outcome, $row] = $this->store->reserve($this->currentCompany->id(), $key, IdempotencyFingerprint::of($request->method(), $request->getPathInfo(), (string) $request->getContent()), $request->method(), $request->getPathInfo());

        return match ($outcome) {
            'mismatch' => ProblemDetails::response(422, 'This Idempotency-Key was already used with another request (method, path or body). Use a new key for a new request.'),
            'in_progress' => ProblemDetails::response(409, 'A request with this Idempotency-Key is still in progress. Wait for it to finish, then repeat it to get its answer.'),
            'replay' => $this->replay($row ?? throw new LogicException('A kept answer has its stored row.')),
            default => $this->run($request, $next, $this->reservedId($row)),
        };
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    private function run(Request $request, Closure $next, string $id): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->store->release($id);

            throw $exception;
        }

        if ($response->getStatusCode() >= 500) {
            $this->store->release($id);
        } else {
            $this->store->complete($id, $response);
        }

        return $response;
    }

    /** The id of the row a reservation stored; the columns of a row read as text. */
    private function reservedId(?stdClass $row): string
    {
        $id = $row?->id;

        return is_string($id) ? $id : throw new LogicException('A reserved Idempotency-Key has its stored row.');
    }

    private function replay(stdClass $row): Response
    {
        $stored = $row->response_headers ?? null;
        $headers = is_string($stored) ? json_decode($stored, true) : [];
        $body = $row->response_body ?? null;
        $status = $row->response_status ?? null;

        return new HttpResponse(
            is_string($body) ? $body : '',
            is_numeric($status) ? (int) $status : 0,
            [...(is_array($headers) ? $headers : []), self::REPLAYED => 'true'],
        );
    }
}
