<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Outside production, fail on lazy loading, unknown attributes and silently discarded fills.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
