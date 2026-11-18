<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use Illuminate\Database\Seeder;

/**
 * DEMO DATA for local development. The invoice range uses the public values of the DIAN test set
 * (prefix SETP, resolution 18760000001 and its technical key); the note ranges are synthetic.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        IdentityDemoSeeder::eachCompany(function (): void {
            NumberTemplate::create([
                'document_type' => DocumentType::Invoice,
                'prefix' => 'SETP',
                'from_number' => 990000000,
                'to_number' => 995000000,
                'next_number' => 990000000,
                'resolution_number' => '18760000001',
                'valid_from' => '2019-01-19',
                'valid_to' => '2030-01-19',
                'technical_key' => 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c',
                'is_default' => true,
            ]);

            foreach ([DocumentType::CreditNote->value => 'NC', DocumentType::DebitNote->value => 'ND'] as $type => $prefix) {
                NumberTemplate::create([
                    'document_type' => $type,
                    'prefix' => $prefix,
                    'from_number' => 1,
                    'to_number' => 99999999,
                    'next_number' => 1,
                    'is_default' => true,
                ]);
            }
        });
    }
}
