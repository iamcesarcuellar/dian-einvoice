<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Actions\CreateCompany;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\FiscalResponsibility;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use Illuminate\Database\Seeder;

/**
 * SYNTHETIC DEMO DATA for local development, set in Medellín. The user, the companies, their NITs
 * and contact details are fictitious and do not identify any real person or business; phones follow
 * the fictitious (604) 555 01NN pattern.
 */
final class DemoSeeder extends Seeder
{
    /** Synthetic NITs of the demo companies; the Company model computes their check digits. */
    public const array NITS = ['990000001', '990000002'];

    public function run(CreateCompany $createCompany): void
    {
        $user = User::create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => 'password',
        ]);

        // Legal entities under the IVA scheme, without special responsibilities, based in Medellín (Antioquia).
        $taxProfile = [
            'organization_type' => OrganizationType::LegalEntity->value,
            'tax_scheme' => TaxScheme::Iva->value,
            'fiscal_responsibilities' => [FiscalResponsibility::NotApplicable->value],
            'municipality_code' => '05001',
        ];

        $createCompany($user, [
            ...$taxProfile,
            'name' => 'Demo Comercial SAS',
            'legal_name' => 'Demo Comercial SAS',
            'identification_number' => self::NITS[0],
            'registration_number' => '0000001',
            'email' => 'billing@demo-comercial.example',
            'phone' => '(604) 555 0101',
            'address' => 'Carrera 48 # 10-45',
            'postal_code' => '050021',
        ]);

        $createCompany($user, [
            ...$taxProfile,
            'name' => 'Demo Servicios SAS',
            'legal_name' => 'Demo Servicios SAS',
            'identification_number' => self::NITS[1],
            'registration_number' => '0000002',
            'email' => 'billing@demo-servicios.example',
            'phone' => '(604) 555 0102',
            'address' => 'Calle 33 # 65-20',
            'postal_code' => '050030',
        ]);
    }

    /**
     * Runs the callback once per demo company, acting as its owner, so tenant models are scoped to it.
     *
     * @param  callable(Company): void  $callback
     */
    public static function eachCompany(callable $callback): void
    {
        $currentCompany = app(CurrentCompany::class);

        $companies = Company::query()
            ->whereIn('identification_number', self::NITS)
            ->orderBy('identification_number')
            ->get();

        foreach ($companies as $company) {
            $membership = Membership::query()
                ->with('company')
                ->where('company_id', $company->getKey())
                ->where('role', Role::Owner)
                ->firstOrFail();

            $currentCompany->set($membership);

            $callback($company);
        }
    }
}
