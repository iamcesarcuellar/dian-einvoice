<?php

namespace App\Modules\Dian\Database\Seeders;

use App\Modules\Dian\Enums\DianEnvironment;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Services\SigningCertificates;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * SYNTHETIC DEMO DATA for local development. The software IDs, PINs and test set IDs below were
 * made up for this project: they are not registered with the DIAN and identify no real software.
 * Each company also gets a generated self-signed TEST certificate, which no accredited
 * certification authority issued; its validity starts before the oldest demo invoice.
 */
final class DemoSeeder extends Seeder
{
    /** Software ID, PIN and test set ID by demo company NIT. */
    private const array SETTINGS = [
        '990000001' => ['00000000-0000-4000-8000-0000000d1a01', '10101', '00000000-0000-4000-8000-00000075e701'],
        '990000002' => ['00000000-0000-4000-8000-0000000d1a02', '20202', '00000000-0000-4000-8000-00000075e702'],
    ];

    /** The demo invoices go back about four months; the test certificates start earlier. */
    private const int CERTIFICATE_DAYS_BACK = 150;

    public function run(SigningCertificates $certificates): void
    {
        IdentityDemoSeeder::eachCompany(function (Company $company) use ($certificates): void {
            [$softwareId, $pin, $testSetId] = self::SETTINGS[$company->identification_number];

            DianSetting::create([
                'environment' => DianEnvironment::Test,
                'software_id' => $softwareId,
                'software_pin' => $pin,
                'test_set_id' => $testSetId,
            ]);

            $certificates->generateTest($company, Carbon::now()->subDays(self::CERTIFICATE_DAYS_BACK)->startOfDay());
        });
    }
}
