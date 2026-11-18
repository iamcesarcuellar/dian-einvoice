<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Resolves the company a request acts on from the X-Company-Id header, after checking that the
 * signed-in user is a member of it. Alias "company"; it runs after "auth:sanctum".
 */
final class ResolveCurrentCompany
{
    public const string HEADER = 'X-Company-Id';

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header(self::HEADER);

        if (! is_string($companyId) || ! Str::isUlid($companyId)) {
            throw new BadRequestHttpException('Send the current company in the X-Company-Id header.');
        }

        $membership = Membership::query()
            ->with('company')
            ->where('company_id', Str::lower($companyId))
            ->where('user_id', $request->user()?->getKey())
            ->first();

        if ($membership === null) {
            throw new AccessDeniedHttpException('You are not a member of this company.');
        }

        $this->currentCompany->set($membership);

        return $next($request);
    }
}
