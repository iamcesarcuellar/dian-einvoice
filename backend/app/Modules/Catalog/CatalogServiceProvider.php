<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Listeners\ProvisionCompanyCatalog;
use App\Modules\Identity\Events\CompanyCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog module: contacts, items, taxes and withholdings of each company.
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTranslationsFrom(__DIR__.'/Lang', 'catalog');

        Event::listen(CompanyCreated::class, ProvisionCompanyCatalog::class);
    }
}
