<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Console\StressNumberingCommand;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Billing module: numbering ranges, invoices, and the credit and debit notes that correct them.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // {document} resolves only to documents of the current company of the type of the route:
        // an invoice under /invoices, a credit note under /credit-notes, a debit note under
        // /debit-notes. Anything else is a 404.
        Route::bind('document', fn (string $value, RoutingRoute $route): Document => Document::query()
            ->where('type', DocumentType::forRoute($route->getName()))
            ->findOrFail($value));

        if ($this->app->runningInConsole()) {
            $this->commands([StressNumberingCommand::class]);
        }
    }
}
