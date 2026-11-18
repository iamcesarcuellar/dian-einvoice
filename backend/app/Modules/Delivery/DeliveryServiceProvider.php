<?php

namespace App\Modules\Delivery;

use App\Modules\Delivery\Console\DeliveryCheckCommand;
use App\Modules\Delivery\LocalMailbox\LocalMailbox;
use App\Modules\Delivery\Pdf\DocumentRenderer;
use App\Modules\Delivery\Pdf\DompdfDocumentRenderer;
use App\Modules\Delivery\Pipeline\BuildAttachedDocument;
use App\Modules\Delivery\Pipeline\DeliveryEvents;
use App\Modules\Delivery\Pipeline\GeneratePdf;
use App\Modules\Delivery\Pipeline\SendDeliveryEmail;
use App\Modules\Delivery\Pipeline\StalledDeliverySweeper;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\Sweeper;
use Illuminate\Support\ServiceProvider;

/**
 * Delivery module: once the local DIAN simulator accepts an invoice, three queued steps build its
 * AttachedDocument, draw its PDF and email both in a zip to the customer through Laravel's mailer
 * (SMTP to the local development mailbox, compose service mail). Blade templates here render only
 * the PDF and the email; the backend serves no HTML.
 */
final class DeliveryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Local adapter of document generation; another engine only needs another binding.
        $this->app->bind(DocumentRenderer::class, DompdfDocumentRenderer::class);

        $this->app->singleton(LocalMailbox::class, fn (): LocalMailbox => new LocalMailbox(
            (string) config('delivery.mailbox.api_url'),
            (string) config('delivery.mailbox.url'),
            (float) config('delivery.mailbox.timeout'),
        ));

        $this->app->tag([StalledDeliverySweeper::class], Sweeper::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'delivery');
        $this->loadViewsFrom(__DIR__.'/Views', 'delivery');

        // Each event of the delivery starts its next step; the relay publishes them to the queue.
        $subscribers = $this->app->make(OutboxSubscribers::class);
        $subscribers->subscribe(DeliveryEvents::ATTACHED_DOCUMENT_DUE, BuildAttachedDocument::class);
        $subscribers->subscribe(DeliveryEvents::PDF_DUE, GeneratePdf::class);
        $subscribers->subscribe(DeliveryEvents::EMAIL_DUE, SendDeliveryEmail::class);

        if ($this->app->runningInConsole()) {
            $this->commands([DeliveryCheckCommand::class]);
        }
    }
}
