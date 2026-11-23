<?php

namespace App\Modules\Payments;

use App\Modules\Payments\Console\PaymentsCheckCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Payments module: payments received from customers, applied to their accepted invoices, and the
 * accounts receivable. Its boundary is clean, as it will become a service of its own: it reaches
 * Billing only through the ports in Ports (Billing implements them), and publishes what happens as
 * outbox events (payment.recorded, payment.voided, invoice.paid).
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'payments');

        if ($this->app->runningInConsole()) {
            $this->commands([PaymentsCheckCommand::class]);
        }
    }
}
