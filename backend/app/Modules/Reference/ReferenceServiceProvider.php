<?php

namespace App\Modules\Reference;

use Illuminate\Support\ServiceProvider;

/**
 * Reference module: DIAN code lists and DANE departments and municipalities.
 */
final class ReferenceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
    }
}
