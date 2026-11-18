<?php

namespace App\Modules\Billing\Tools;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Services\SigningCertificates;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Identity\Actions\CreateCompany;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\FiscalResponsibility;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Development tool for make stress, make chaos and make payments-check: a SYNTHETIC company with everything issuing
 * needs (tax profile, made-up DIAN software, a test certificate and a numbering range with a random
 * technical key), and its deletion afterwards, with its files and its records in the simulator.
 */
final class TemporaryCompany
{
    /**
     * Tables with company_id, children first: payments and documents hold restrictive keys on
     * contacts, items, taxes, withholdings and numbering. Lines, events, transmissions and the
     * invoice side of the payments go with their documents; allocations and events with their payments.
     */
    private const array TABLES = ['payment_events', 'payment_allocations', 'payments', 'outbox_events', 'documents', 'dian_file_counters', 'number_templates', 'items', 'contacts', 'taxes', 'withholdings', 'dian_settings', 'signing_certificates', 'key_store_entries', 'memberships'];

    private function __construct(
        public readonly Company $company,
        public readonly User $user,
    ) {}

    /** Creates the company and makes it the current one, acting as its owner. */
    public static function create(string $name, string $prefix): self
    {
        $user = User::create([
            'name' => $name,
            'email' => Str::slug($name).'-'.Str::lower((string) Str::ulid()).'@example.com',
            'password' => Str::random(40),
        ]);

        try {
            $company = app(CreateCompany::class)($user, [
                'name' => $name.' '.Carbon::now()->format('His'),
                'identification_number' => self::freeNit(),
                'organization_type' => OrganizationType::LegalEntity->value,
                'tax_scheme' => TaxScheme::Iva->value,
                'fiscal_responsibilities' => [FiscalResponsibility::NotApplicable->value],
                'email' => 'temporary@example.com',
                'address' => 'Carrera 1 # 1-01',
                'municipality_code' => '05001',
            ]);
        } catch (Throwable $exception) {
            $user->delete();

            throw $exception;
        }

        $temporary = new self($company, $user);
        $temporary->actAsOwner();

        DianSetting::create(['software_id' => (string) Str::uuid(), 'software_pin' => Str::random(8)]);
        app(SigningCertificates::class)->generateTest($company);

        $today = Carbon::now(NumberTemplate::TIMEZONE);

        NumberTemplate::create([
            'document_type' => NumberingType::Invoice,
            'prefix' => $prefix,
            'from_number' => 1,
            'to_number' => 999999,
            'next_number' => 1,
            'resolution_number' => 'TEMP-'.$prefix,
            'resolution_date' => $today->copy()->subDay()->toDateString(),
            'valid_from' => $today->copy()->subDay()->toDateString(),
            'valid_to' => $today->copy()->addYear()->toDateString(),
            'technical_key' => Str::random(40),
            'is_default' => true,
        ]);

        return $temporary;
    }

    /** A temporary company created by an earlier process, such as the first step of make chaos. */
    public static function find(string $companyId): ?self
    {
        $company = Company::query()->find($companyId);
        $ownerId = $company === null ? null : Membership::query()->where('company_id', $companyId)->where('role', Role::Owner)->value('user_id');
        $owner = $ownerId === null ? null : User::query()->find($ownerId);

        if ($company === null || $owner === null) {
            return null;
        }

        $temporary = new self($company, $owner);
        $temporary->actAsOwner();

        return $temporary;
    }

    public function numbering(): NumberTemplate
    {
        return NumberTemplate::query()->where('document_type', DocumentType::Invoice)->where('is_default', true)->firstOrFail();
    }

    /** The final consumer, which every company gets. */
    public function customer(): Contact
    {
        return Contact::query()->where('is_system', true)->firstOrFail();
    }

    public function iva(): ?string
    {
        return Tax::query()->where('name', 'IVA 19%')->value('id');
    }

    /**
     * Deletes the company with everything in it, its stored files, the user, and what the local DIAN
     * simulator keeps about its NIT. The simulator part is best effort.
     */
    public function delete(): void
    {
        $companyId = $this->company->getKey();

        foreach (self::TABLES as $table) {
            DB::table($table)->where('company_id', $companyId)->delete();
        }

        DB::table('companies')->where('id', $companyId)->delete();
        $this->user->delete();

        app(DocumentStorage::class)->deletePrefix($companyId);

        try {
            app(DianSimulator::class)->forgetTaxpayer($this->company->identification_number);
        } catch (Throwable) {
            // The simulator keeps a few records of a NIT nobody uses; harmless.
        }
    }

    private function actAsOwner(): void
    {
        $membership = Membership::query()
            ->with('company')
            ->where('company_id', $this->company->getKey())
            ->where('role', Role::Owner)
            ->firstOrFail();

        app(CurrentCompany::class)->set($membership);
    }

    /** A synthetic NIT no company uses yet. */
    private static function freeNit(): string
    {
        do {
            $nit = '98'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
        } while (Company::query()->where('identification_number', $nit)->exists());

        return $nit;
    }
}
