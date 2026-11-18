<?php

namespace Database\Seeders;

use App\Modules\Billing\Database\Seeders\DemoSeeder as BillingDemoSeeder;
use App\Modules\Catalog\Database\Seeders\DemoSeeder as CatalogDemoSeeder;
use App\Modules\Dian\Database\Seeders\DemoSeeder as DianDemoSeeder;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Support\Storage\DocumentStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Synthetic demo data for local development only. DIAN settings and test certificates come
     * before the invoices: issuing needs them, and every issued demo invoice gets its CUFE and
     * signed XML from the real services.
     */
    public function run(DocumentStorage $storage): void
    {
        // make seed has just recreated the database, so every stored XML is an orphan. The guard
        // keeps the files of a database that still has documents.
        if (DB::table('documents')->doesntExist()) {
            $storage->purge();
        }

        $this->call([
            IdentityDemoSeeder::class,
            CatalogDemoSeeder::class,
            DianDemoSeeder::class,
            BillingDemoSeeder::class,
        ]);
    }
}
