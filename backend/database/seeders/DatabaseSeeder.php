<?php

namespace Database\Seeders;

use App\Modules\Billing\Database\Seeders\DemoSeeder as BillingDemoSeeder;
use App\Modules\Catalog\Database\Seeders\DemoSeeder as CatalogDemoSeeder;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Synthetic demo data for local development only.
     */
    public function run(): void
    {
        $this->call([
            IdentityDemoSeeder::class,
            CatalogDemoSeeder::class,
            BillingDemoSeeder::class,
        ]);
    }
}
