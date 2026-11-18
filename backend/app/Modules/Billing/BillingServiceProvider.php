<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Console\StressNumberingCommand;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Ledger\BillingInvoiceLedger;
use App\Modules\Billing\Ledger\BillingReceiptNumbering;
use App\Modules\Billing\Listeners\ProvisionReceiptNumbering;
use App\Modules\Billing\Models\Document;
use App\Modules\Identity\Events\CompanyCreated;
use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Ports\ReceiptNumbering;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Billing module: numbering ranges, invoices, the credit and debit notes that correct them, and
 * the invoice side of the payments: Billing implements the ports the payments module talks through.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InvoiceLedger::class, BillingInvoiceLedger::class);
        $this->app->bind(ReceiptNumbering::class, BillingReceiptNumbering::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        Event::listen(CompanyCreated::class, ProvisionReceiptNumbering::class);

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
