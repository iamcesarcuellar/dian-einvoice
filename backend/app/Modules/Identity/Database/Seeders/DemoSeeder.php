<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Actions\CreateCompany;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Team\TeamInvitations;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\FiscalResponsibility;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use Carbon\FactoryImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * SYNTHETIC DEMO DATA for local development, set in Medellín. The users, the companies, their NITs
 * and contact details are fictitious and do not identify any real person or business; phones follow
 * the fictitious (604) 555 01NN pattern, and every email address is on example.com or .example.
 *
 * demo@example.com owns both companies. Demo Comercial SAS also has a team: an admin, a member and
 * a viewer, all with the password "password", and a pending invitation to invitee@example.com, made
 * and emailed through the real invitation service, so its email waits in the local mailbox. The
 * companies, users and memberships are dated months back (Carbon test time), before the oldest demo
 * invoice, so the team list shows plausible joining dates; the invitation is made today.
 */
final class DemoSeeder extends Seeder
{
    /** Synthetic NITs of the demo companies; the Company model computes their check digits. */
    public const array NITS = ['990000001', '990000002'];

    /** The password of every demo user. */
    public const string PASSWORD = 'password';

    /** The owner of both demo companies. */
    public const string OWNER_EMAIL = 'demo@example.com';

    /** The team of Demo Comercial SAS besides its owner: name, email, role and days since joining. */
    public const array TEAM = [
        ['Demo Admin', 'admin@example.com', Role::Admin, 235],
        ['Demo Member', 'member@example.com', Role::Member, 228],
        ['Demo Viewer', 'viewer@example.com', Role::Viewer, 46],
    ];

    /** The address of the pending invitation of Demo Comercial SAS, and its role. */
    public const string INVITEE_EMAIL = 'invitee@example.com';

    /** Days back the owner and the companies were created. */
    private const int OWNER_DAYS_BACK = 240;

    public function run(CreateCompany $createCompany, TeamInvitations $invitations): void
    {
        $today = Carbon::now();
        // The clock it found, put back at the end: the clock of the machine, or the story clock of make screenshots.
        $clock = FactoryImmutable::getDefaultInstance()->getTestNow();

        try {
            Carbon::setTestNow($today->copy()->subDays(self::OWNER_DAYS_BACK));

            $user = User::create([
                'name' => 'Demo User',
                'email' => self::OWNER_EMAIL,
                'password' => self::PASSWORD,
            ]);

            // Legal entities that charge IVA (responsibility O-48 of table 13.2.6.1, "Impuesto sobre
            // las ventas"), without other responsibilities, based in Medellín (Antioquia).
            $taxProfile = [
                'organization_type' => OrganizationType::LegalEntity->value,
                'tax_scheme' => TaxScheme::Iva->value,
                'fiscal_responsibilities' => [FiscalResponsibility::SalesTax->value],
                'municipality_code' => '05001',
            ];

            $commerce = $createCompany($user, [
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

            foreach (self::TEAM as [$name, $email, $role, $daysBack]) {
                Carbon::setTestNow($today->copy()->subDays($daysBack));

                $member = User::create(['name' => $name, 'email' => $email, 'password' => self::PASSWORD]);

                Membership::create([
                    'company_id' => $commerce->getKey(),
                    'user_id' => $member->getKey(),
                    'role' => $role,
                ]);
            }
        } finally {
            Carbon::setTestNow($clock);
        }

        // Through the real service, acting as the owner of Demo Comercial SAS: the email lands in the local mailbox.
        self::eachCompany(function (Company $company) use ($invitations, $user): void {
            if ($company->identification_number !== self::NITS[0]) {
                return;
            }

            [, $sent] = $invitations->invite(self::INVITEE_EMAIL, Role::Member, $user);

            if (! $sent) {
                $this->command?->warn('The local mailbox did not take the demo invitation email; resend it from Settings → Team.');
            }
        });
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
