<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Http\Middleware\EnsureCompanyPermission;
use App\Modules\Identity\Http\Middleware\ResolveCurrentCompany;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Policies\CompanyPolicy;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Identity module: users, sign-in and profile, companies, memberships and the team of each company
 * (roles and email invitations), plus the tenancy building blocks. Its Blade templates render only
 * the invitation email; the backend serves no HTML.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'identity');
        $this->loadViewsFrom(__DIR__.'/Views', 'identity');

        Gate::policy(Company::class, CompanyPolicy::class);

        // Tenant routes use ['auth:sanctum', 'company'] to act on the company sent in X-Company-Id,
        // and add 'permission:<name>' on the routes that change data.
        Route::aliasMiddleware('company', ResolveCurrentCompany::class);
        Route::aliasMiddleware('permission', EnsureCompanyPermission::class);

        // A {company} route parameter only resolves to a company the signed-in user belongs to,
        // with that membership loaded. Anything else is a 404, so other tenants stay invisible.
        Route::bind('company', function (string $value): Company {
            $user = request()->user();

            abort_if($user === null, 404);

            return $user->companies()->findOrFail($value);
        });
    }
}
