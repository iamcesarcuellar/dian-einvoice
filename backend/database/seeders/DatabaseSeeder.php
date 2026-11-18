<?php

namespace Database\Seeders;

use App\Modules\Billing\Database\Seeders\DemoSeeder as BillingDemoSeeder;
use App\Modules\Catalog\Database\Seeders\DemoSeeder as CatalogDemoSeeder;
use App\Modules\Dian\Database\Seeders\DemoSeeder as DianDemoSeeder;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Synthetic demo data for local development only. DIAN settings come before the invoices:
     * issuing needs them, and every issued demo invoice gets its CUFE and XML from the real service.
     */
    public function run(): void
    {
        $this->call([
            IdentityDemoSeeder::class,
            CatalogDemoSeeder::class,
            DianDemoSeeder::class,
            BillingDemoSeeder::class,
        ]);
    }
}
