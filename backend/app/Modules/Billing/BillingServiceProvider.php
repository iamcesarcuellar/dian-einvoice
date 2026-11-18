<?php

namespace App\Modules\Billing;

use Illuminate\Support\ServiceProvider;

/**
 * Billing module: numbering ranges now; invoices and notes from stage 3 on.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
