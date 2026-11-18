<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Services\InvoiceDrafts;
use App\Modules\Billing\Services\IssueInvoice;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Services\SigningCertificates;
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
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Development tool. On a temporary company of its own, it creates drafts, issues them from
 * parallel worker processes and checks the numbers are consecutive, with no gaps or repeats.
 * The company and everything in it is deleted at the end.
 */
final class StressNumberingCommand extends Command
{
    protected $signature = 'billing:stress
        {--invoices=60 : Invoices to issue}
        {--concurrency=20 : Worker processes issuing at the same time}';

    protected $description = 'Issue invoices from parallel processes on a temporary company and check the numbering has no gaps or repeats';

    public function handle(CreateCompany $createCompany, InvoiceDrafts $drafts, IssueInvoice $issue, CurrentCompany $currentCompany, SigningCertificates $certificates): int
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('The pcntl extension is missing; rebuild the api image with make up.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('invoices'));
        $workers = max(1, min($count, (int) $this->option('concurrency')));

        $user = User::create([
            'name' => 'Stress Test',
            'email' => 'stress-'.Str::lower((string) Str::ulid()).'@example.com',
            'password' => Str::random(40),
        ]);

        $company = null;

        try {
            // A complete tax profile, synthetic DIAN software and a test certificate: issuing requires them.
            $company = $createCompany($user, [
                'name' => 'Stress Test '.Carbon::now()->format('His'),
                'identification_number' => $this->freeNit(),
                'organization_type' => OrganizationType::LegalEntity->value,
                'tax_scheme' => TaxScheme::Iva->value,
                'fiscal_responsibilities' => [FiscalResponsibility::NotApplicable->value],
                'email' => 'stress@example.com',
                'address' => 'Carrera 1 # 1-01',
                'municipality_code' => '05001',
            ]);

            $membership = Membership::query()
                ->with('company')
                ->where('company_id', $company->getKey())
                ->where('role', Role::Owner)
                ->firstOrFail();

            $currentCompany->set($membership);

            DianSetting::create(['software_id' => (string) Str::uuid(), 'software_pin' => Str::random(8)]);
            $certificates->generateTest($company);

            $template = $this->createNumbering();
            $customer = Contact::query()->where('is_system', true)->firstOrFail();
            $taxId = Tax::query()->where('name', 'IVA 19%')->value('id');

            $ids = [];

            for ($index = 1; $index <= $count; $index++) {
                $ids[] = $drafts->create([
                    'contact_id' => $customer->getKey(),
                    'number_template_id' => $template->getKey(),
                    'lines' => [
                        ['description' => 'Stress test product', 'quantity' => '2', 'unit_price' => '15000', 'tax_id' => $taxId],
                        ['description' => 'Stress test service', 'quantity' => '1', 'unit_price' => (string) (1000 * $index), 'tax_id' => null],
                    ],
                ], $user)->getKey();
            }

            $this->line("Temporary company {$company->name}: {$count} drafts created.");

            $started = hrtime(true);
            $failures = $this->issueInParallel($ids, $workers, $issue, $user);
            $seconds = (hrtime(true) - $started) / 1e9;

            return $this->report($template->refresh(), $count, $workers, $failures, $seconds);
        } finally {
            if ($company !== null) {
                $this->deleteCompany($company->getKey());
            }

            $user->delete();
            $this->line('Temporary company deleted.');
        }
    }

    /**
     * Forks the workers; each one issues its share of the drafts with its own database connection.
     *
     * @param  list<string>  $ids
     * @return int Issues that failed.
     */
    private function issueInParallel(array $ids, int $workers, IssueInvoice $issue, User $user): int
    {
        // A forked child must never share the parent's connection.
        DB::disconnect();

        $children = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not start a worker process.');
            }

            if ($pid === 0) {
                $failed = 0;

                foreach ($ids as $index => $id) {
                    if ($index % $workers !== $worker) {
                        continue;
                    }

                    try {
                        $issue($id, $user);
                    } catch (Throwable $exception) {
                        $failed++;
                        fwrite(STDERR, sprintf("Worker %d, draft %s: %s\n", $worker + 1, $id, $exception->getMessage()));
                    }
                }

                DB::disconnect();

                exit(min($failed, 255));
            }

            $children[] = $pid;
        }

        $failed = 0;

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $failed += pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
        }

        return $failed;
    }

    private function report(NumberTemplate $template, int $count, int $workers, int $failures, float $seconds): int
    {
        $numbers = Document::query()
            ->where('status', DocumentStatus::Issued)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($number): int => (int) $number)
            ->all();

        $issued = count($numbers);
        $first = $numbers[0] ?? 0;
        $last = $numbers === [] ? 0 : $numbers[$issued - 1];
        $repeated = array_values(array_unique(array_diff_assoc($numbers, array_unique($numbers))));
        $missing = $numbers === [] ? [] : array_values(array_diff(range($first, $last), $numbers));

        $this->line(sprintf('Issued %d of %d invoices with %d parallel workers in %.2f s (%d failed).', $issued, $count, $workers, $seconds, $failures));

        $ok = $failures === 0
            && $issued === $count
            && $repeated === []
            && $missing === []
            && $first === $template->from_number
            && $template->next_number === $template->from_number + $count;

        if ($ok) {
            $this->info(sprintf(
                'Numbers %s%d to %s%d: %d consecutive numbers, no gaps and no repeats.',
                $template->prefix, $first, $template->prefix, $last, $issued,
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Numbering check failed: first %d, last %d, next %d, repeated [%s], missing [%s].',
            $first, $last, $template->next_number, implode(', ', $repeated), implode(', ', array_slice($missing, 0, 20)),
        ));

        return self::FAILURE;
    }

    private function createNumbering(): NumberTemplate
    {
        $today = Carbon::now(NumberTemplate::TIMEZONE);

        return NumberTemplate::create([
            'document_type' => DocumentType::Invoice,
            'prefix' => 'ST',
            'from_number' => 1,
            'to_number' => 999999,
            'next_number' => 1,
            'resolution_number' => 'STRESS-TEST',
            'valid_from' => $today->copy()->subDay()->toDateString(),
            'valid_to' => $today->copy()->addYear()->toDateString(),
            'technical_key' => Str::random(40),
            'is_default' => true,
        ]);
    }

    /** A synthetic NIT no company uses yet. */
    private function freeNit(): string
    {
        do {
            $nit = '98'.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
        } while (Company::query()->where('identification_number', $nit)->exists());

        return $nit;
    }

    /**
     * Deletes the temporary company and the XML files of its invoices. Documents go first: they
     * hold restrictive keys on contacts, items, taxes, withholdings and numbering.
     */
    private function deleteCompany(string $companyId): void
    {
        foreach (['outbox_events', 'documents', 'number_templates', 'items', 'contacts', 'taxes', 'withholdings', 'dian_settings', 'signing_certificates', 'key_store_entries', 'memberships'] as $table) {
            DB::table($table)->where('company_id', $companyId)->delete();
        }

        DB::table('companies')->where('id', $companyId)->delete();

        app(DocumentStorage::class)->deletePrefix($companyId);
    }
}
