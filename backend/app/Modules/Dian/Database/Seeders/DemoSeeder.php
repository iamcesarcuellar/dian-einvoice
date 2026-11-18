<?php

namespace App\Modules\Dian\Database\Seeders;

use App\Modules\Dian\Enums\DianEnvironment;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use Illuminate\Database\Seeder;

/**
 * SYNTHETIC DEMO DATA for local development. The software IDs, PINs and test set IDs below were
 * made up for this project: they are not registered with the DIAN and identify no real software.
 */
final class DemoSeeder extends Seeder
{
    /** Software ID, PIN and test set ID by demo company NIT. */
    private const array SETTINGS = [
        '990000001' => ['00000000-0000-4000-8000-0000000d1a01', '10101', '00000000-0000-4000-8000-00000075e701'],
        '990000002' => ['00000000-0000-4000-8000-0000000d1a02', '20202', '00000000-0000-4000-8000-00000075e702'],
    ];

    public function run(): void
    {
        IdentityDemoSeeder::eachCompany(function (Company $company): void {
            [$softwareId, $pin, $testSetId] = self::SETTINGS[$company->identification_number];

            DianSetting::create([
                'environment' => DianEnvironment::Test,
                'software_id' => $softwareId,
                'software_pin' => $pin,
                'test_set_id' => $testSetId,
            ]);
        });
    }
}
