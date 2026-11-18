<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Models\ApiKey;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Resolves the company a request acts on. With a session, from the X-Company-Id header, after
 * checking that the signed-in user is a member of it. With an API key, it is the company of the
 * key: X-Company-Id is not needed and, when sent, must be that company. A read-only key only
 * reads. Alias "company"; it runs after "auth".
 */
final class ResolveCurrentCompany
{
    public const string HEADER = 'X-Company-Id';

    /** Methods that change nothing. */
    private const array READS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header(self::HEADER);
        $principal = $request->user();

        if ($principal instanceof ApiKey) {
            if (is_string($companyId) && $companyId !== '' && Str::lower($companyId) !== $principal->company_id) {
                throw new AccessDeniedHttpException('This API key belongs to another company. Leave X-Company-Id out, or send the company of the key.');
            }

            if ($principal->level === ApiKeyLevel::ReadOnly && ! in_array($request->method(), self::READS, true)) {
                throw new AccessDeniedHttpException(sprintf('The API key %s is read-only: it can only read.', $principal->visibleId()));
            }

            $this->currentCompany->setApiKey($principal);

            return $next($request);
        }

        if (! is_string($companyId) || ! Str::isUlid($companyId)) {
            throw new BadRequestHttpException('Send the current company in the X-Company-Id header.');
        }

        $membership = Membership::query()
            ->with('company')
            ->where('company_id', Str::lower($companyId))
            ->where('user_id', $principal?->getKey())
            ->first();

        if ($membership === null) {
            throw new AccessDeniedHttpException('You are not a member of this company.');
        }

        $this->currentCompany->set($membership);

        return $next($request);
    }
}
