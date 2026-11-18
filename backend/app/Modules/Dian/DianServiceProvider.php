<?php

namespace App\Modules\Dian;

use App\Modules\Dian\Console\DianCheckCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Dian module: DIAN settings of each company, the CUFE and software security code, and the UBL
 * 2.1 XML of issued invoices. Signature (stage 5) and transmission (stage 6) arrive later.
 */
final class DianServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([DianCheckCommand::class]);
        }
    }
}
