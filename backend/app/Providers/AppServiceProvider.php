<?php

namespace App\Providers;

use App\Support\Keys\KeyStore;
use App\Support\Keys\LocalKeyStore;
use App\Support\Storage\DocumentStorage;
use App\Support\Storage\LocalDocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Local adapters; Infrastructure binds a real object store and a key management service here.
        $this->app->singleton(DocumentStorage::class, LocalDocumentStorage::class);
        $this->app->singleton(KeyStore::class, LocalKeyStore::class);
    }

    public function boot(): void
    {
        // Outside production, fail on lazy loading, unknown attributes and silently discarded fills.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
