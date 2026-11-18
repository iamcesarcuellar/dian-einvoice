<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Dian\DianServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Reference\ReferenceServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ReferenceServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    BillingServiceProvider::class,
    DianServiceProvider::class,
];
