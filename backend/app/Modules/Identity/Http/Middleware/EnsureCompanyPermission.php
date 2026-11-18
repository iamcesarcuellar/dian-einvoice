<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lets the request through only when the user's role in the current company has the permission.
 * Alias "permission", used after "company": permission:manage-catalog.
 */
final class EnsureCompanyPermission
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->currentCompany->role()->can(Permission::from($permission))) {
            throw new AccessDeniedHttpException('Your role in this company does not allow this action.');
        }

        return $next($request);
    }
}
