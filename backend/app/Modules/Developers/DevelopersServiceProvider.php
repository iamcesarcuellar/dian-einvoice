<?php

namespace App\Modules\Developers;

use App\Modules\Developers\Auth\ApiKeyAuthenticator;
use App\Modules\Developers\Console\ApiCheckCommand;
use App\Modules\Developers\Console\WebhooksStatusCommand;
use App\Modules\Developers\Http\Middleware\HandleIdempotency;
use App\Modules\Developers\Http\Middleware\RateLimitApiKeys;
use App\Modules\Developers\Http\Middleware\RejectApiKeys;
use App\Modules\Developers\Idempotency\PruneIdempotencyKeys;
use App\Modules\Developers\Models\ApiKey;
use App\Modules\Developers\RateLimits\CacheWindowLimiter;
use App\Modules\Developers\RateLimits\RedisWindowLimiter;
use App\Modules\Developers\RateLimits\WindowLimiter;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Retention\PruneWebhookDeliveries;
use App\Modules\Developers\Webhooks\DeliverWebhook;
use App\Modules\Developers\Webhooks\PublishWebhookEvent;
use App\Modules\Developers\Webhooks\StalledWebhookSweeper;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookPublisher;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\Sweeper;
use App\Support\Retention\Pruner;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Developers module: the same API the web app uses, opened to integrations. API keys per company
 * (guard api-key: Authorization: Bearer dei_...), a rate limit per key (in Redis locally, in DynamoDB
 * in the emulated cloud), Idempotency-Key on any POST, and webhooks signed per Standard Webhooks,
 * published from the outbox and delivered at least once with retries, from the webhooks channel. The
 * local webhook receiver, a development tool, lives in the simulator.
 */
final class DevelopersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocalWebhookReceiver::class, fn (): LocalWebhookReceiver => new LocalWebhookReceiver(
            (string) config('developers.receiver.url'),
            (string) config('developers.receiver.admin_url'),
            (string) config('developers.receiver.token'),
            (float) config('developers.receiver.timeout'),
        ));

        $this->app->bind(WindowLimiter::class, fn (Application $app): WindowLimiter => config('adapters.api_key_limits') === 'cache'
            ? new CacheWindowLimiter($app->make(RateLimiter::class))
            : new RedisWindowLimiter($app->make(Redis::class)));

        $this->app->tag([StalledWebhookSweeper::class, PruneIdempotencyKeys::class], Sweeper::class);
        $this->app->tag([PruneWebhookDeliveries::class], Pruner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'developers');

        // API keys authenticate through their own guard; Sanctum keeps the session of the web app
        // only and never reads bearer tokens, which all belong to API keys.
        Auth::viaRequest(ApiKeyAuthenticator::GUARD, fn (Request $request): ?ApiKey => $this->app->make(ApiKeyAuthenticator::class)($request));
        Sanctum::getAccessTokenFromRequestUsing(fn (): ?string => null);

        Route::aliasMiddleware('api-rate-limit', RateLimitApiKeys::class);
        Route::aliasMiddleware('idempotency', HandleIdempotency::class);
        Route::aliasMiddleware('panel', RejectApiKeys::class);

        // Each committed change with a public event becomes a webhook event; each delivery attempt
        // is an outbox event of its own, so retries survive restarts like the rest of the chain. Both
        // run from the webhooks channel, apart from the chain.
        $subscribers = $this->app->make(OutboxSubscribers::class);

        foreach (WebhookEventTypes::SOURCES as $type) {
            $subscribers->subscribe($type, PublishWebhookEvent::class, OutboxSubscribers::WEBHOOKS);
        }

        $subscribers->subscribe(WebhookPublisher::DELIVERY_DUE, DeliverWebhook::class, OutboxSubscribers::WEBHOOKS);

        if ($this->app->runningInConsole()) {
            $this->commands([ApiCheckCommand::class, WebhooksStatusCommand::class]);
        }
    }
}
