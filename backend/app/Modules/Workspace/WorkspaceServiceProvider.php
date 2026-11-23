<?php

namespace App\Modules\Workspace;

use App\Modules\Identity\Tenancy\Actor;
use App\Modules\Workspace\Console\UiCheckCommand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Workspace module: the screens of the web app that look across modules, the dashboard and the
 * global search. It is a read model: it never writes, and it reads the tables of Billing, Payments,
 * Catalog, Dian and Identity directly, always limited to the current company, because one answer
 * made of aggregate queries is what a dashboard needs. When Payments becomes a service of its own,
 * its share of these answers (payments collected and their timeline) moves behind its API.
 */
final class WorkspaceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'workspace');

        // The search of the command palette asks as the user types: 120 searches a minute per user.
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(120)->by('search|'.Actor::limitKey($request)));

        if ($this->app->runningInConsole()) {
            $this->commands([UiCheckCommand::class]);
        }
    }
}
