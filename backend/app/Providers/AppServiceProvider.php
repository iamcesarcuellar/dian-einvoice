<?php

namespace App\Providers;

use App\Support\ApiDocs\ApiDocumentation;
use App\Support\Health\HealthCheckCommand;
use App\Support\Health\Heartbeat;
use App\Support\Keys\KeyStore;
use App\Support\Keys\LocalKeyStore;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\PruneOutboxEvents;
use App\Support\Outbox\RelayCommand;
use App\Support\Outbox\Sweeper;
use App\Support\Retention\PruneCommand;
use App\Support\Retention\PruneFailedJobs;
use App\Support\Retention\Pruner;
use App\Support\Retention\ScheduledPruning;
use App\Support\Storage\DocumentStorage;
use App\Support\Storage\LocalDocumentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Local adapters; Infrastructure binds a real object store and a key management service here.
        // The queue is Redis through Laravel's queue interface, so a managed queue only changes config.
        $this->app->singleton(DocumentStorage::class, LocalDocumentStorage::class);
        $this->app->singleton(KeyStore::class, LocalKeyStore::class);

        // Modules subscribe their jobs to outbox event types in their providers.
        $this->app->singleton(OutboxSubscribers::class);

        // Retention: modules tag their pruners; the relay runs them once an hour (ScheduledPruning).
        $this->app->tag([PruneOutboxEvents::class, PruneFailedJobs::class], Pruner::class);
        $this->app->tag([ScheduledPruning::class], Sweeper::class);
    }

    public function boot(): void
    {
        // Outside production, fail on lazy loading, unknown attributes and silently discarded fills.
        Model::shouldBeStrict(! $this->app->isProduction());

        // The public API reference at /docs/api, and openapi.json through make openapi.
        ApiDocumentation::configure();

        // The queue workers beat while they loop, for the healthcheck of their containers.
        Event::listen(Looping::class, fn () => Heartbeat::beat('worker'));

        if ($this->app->runningInConsole()) {
            $this->commands([RelayCommand::class, HealthCheckCommand::class, PruneCommand::class]);
        }
    }
}
