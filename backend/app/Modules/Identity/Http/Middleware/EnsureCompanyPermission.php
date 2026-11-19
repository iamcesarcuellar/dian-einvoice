<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets the request through only when whoever acts in the current company has the permission: the
 * role of the signed-in user, or the level of the API key. Alias "permission", used after
 * "company": permission:manage-catalog.
 */
final class EnsureCompanyPermission
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $permission = Permission::from($permission);

        if (! $this->currentCompany->can($permission)) {
            throw new AccessDeniedHttpException(match (true) {
                $this->currentCompany->apiKey() === null => 'Your role in this company does not allow this action.',
                $permission === Permission::ManageSettings => 'API keys cannot change settings: an owner or admin does it in the web app.',
                default => 'This API key does not allow this action.',
            });
        }

        return $next($request);
    }
}
