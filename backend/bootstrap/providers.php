<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Delivery\DeliveryServiceProvider;
use App\Modules\Developers\DevelopersServiceProvider;
use App\Modules\Dian\DianServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Reference\ReferenceServiceProvider;
use App\Modules\Workspace\WorkspaceServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ReferenceServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    BillingServiceProvider::class,
    DianServiceProvider::class,
    DeliveryServiceProvider::class,
    PaymentsServiceProvider::class,
    DevelopersServiceProvider::class,
    WorkspaceServiceProvider::class,
];
