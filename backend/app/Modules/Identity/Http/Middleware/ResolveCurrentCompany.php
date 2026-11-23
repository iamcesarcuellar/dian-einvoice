<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Tenancy\Actor;
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

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header(self::HEADER);
        $apiKey = Actor::apiKey($request);

        if ($apiKey !== null) {
            if (is_string($companyId) && $companyId !== '' && Str::lower($companyId) !== $apiKey->company_id) {
                throw new AccessDeniedHttpException(__('identity::errors.other_company_key'));
            }

            if ($apiKey->level === ApiKeyLevel::ReadOnly && ! in_array($request->method(), self::READS, true)) {
                throw new AccessDeniedHttpException(__('identity::errors.read_only_key', ['key' => $apiKey->visibleId()]));
            }

            $this->currentCompany->setApiKey($apiKey);

            return $next($request);
        }

        if (! is_string($companyId) || ! Str::isUlid($companyId)) {
            throw new BadRequestHttpException(__('identity::errors.missing_company'));
        }

        $membership = Membership::query()
            ->with('company')
            ->where('company_id', Str::lower($companyId))
            ->where('user_id', $request->user()?->getKey())
            ->first();

        if ($membership === null) {
            throw new AccessDeniedHttpException(__('identity::errors.not_member'));
        }

        $this->currentCompany->set($membership);

        return $next($request);
    }
}
