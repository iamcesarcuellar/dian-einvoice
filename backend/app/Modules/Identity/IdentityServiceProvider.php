<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Http\Middleware\EnsureCompanyPermission;
use App\Modules\Identity\Http\Middleware\ResolveCurrentCompany;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\CompanyPolicy;
use App\Modules\Identity\Retention\PruneInvitations;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Retention\Pruner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Identity module: users, sign-in and profile, companies, memberships and the team of each company
 * (roles and email invitations), password recovery, plus the tenancy building blocks. Its Blade
 * templates render only the invitation and password reset emails; the backend serves no HTML.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public const int PASSWORD_MIN = 12;

    public const int PASSWORD_MAX = 128;

    public function register(): void
    {
        $this->app->scoped(CurrentCompany::class);
        $this->app->tag([PruneInvitations::class], Pruner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'identity');
        $this->loadViewsFrom(__DIR__.'/Views', 'identity');

        Gate::policy(Company::class, CompanyPolicy::class);

        // One password policy for registration, invitations, the profile and the reset link: 12 to
        // 128 characters, without composition rules (bcrypt reads the first 72 bytes).
        Password::defaults(fn (): Password => Password::min(self::PASSWORD_MIN)->max(self::PASSWORD_MAX));

        // Limits per address (IP) of the public endpoints, on top of the failed sign-ins per email
        // and address of LoginRequest and the one reset link a minute per account of the broker.
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(10)->by('register|'.$request->ip()));
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(20)->by('login|'.$request->ip()));
        RateLimiter::for('password-email', fn (Request $request): Limit => Limit::perHour(10)->by('password-email|'.$request->ip()));
        RateLimiter::for('password-reset', fn (Request $request): Limit => Limit::perMinute(10)->by('password-reset|'.$request->ip()));
        RateLimiter::for('invitation', fn (Request $request): Limit => Limit::perMinute(30)->by('invitation|'.$request->ip()));
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)->by('invitation-accept|'.$request->ip()));

        // New companies of a signed-in user: a NIT already registered is refused, so creating one is
        // limited too, ten an hour per user.
        RateLimiter::for('companies', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perHour(10)->by('companies|'.($user instanceof User ? $user->id : $request->ip()));
        });

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
