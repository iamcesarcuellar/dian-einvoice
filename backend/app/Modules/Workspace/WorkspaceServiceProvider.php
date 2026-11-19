<?php

namespace App\Modules\Workspace;

use App\Modules\Workspace\Console\UiCheckCommand;
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
        if ($this->app->runningInConsole()) {
            $this->commands([UiCheckCommand::class]);
        }
    }
}
