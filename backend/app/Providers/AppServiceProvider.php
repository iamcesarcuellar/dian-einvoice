<?php

namespace App\Providers;

use App\Support\Storage\DocumentStorage;
use App\Support\Storage\LocalDocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Local adapter; Infrastructure binds a real object store here.
        $this->app->singleton(DocumentStorage::class, LocalDocumentStorage::class);
    }

    public function boot(): void
    {
        // Outside production, fail on lazy loading, unknown attributes and silently discarded fills.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
