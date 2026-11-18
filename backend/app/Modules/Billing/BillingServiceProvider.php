<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Console\StressNumberingCommand;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Billing module: numbering ranges and invoices; credit and debit notes from stage 8.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // {invoice} resolves only to invoices of the current company; anything else is a 404.
        Route::bind('invoice', fn (string $value): Document => Document::query()
            ->where('type', DocumentType::Invoice)
            ->findOrFail($value));

        if ($this->app->runningInConsole()) {
            $this->commands([StressNumberingCommand::class]);
        }
    }
}
