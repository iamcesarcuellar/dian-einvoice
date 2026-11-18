<?php

namespace App\Modules\Dian\Console;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Domain\Cufe;
use App\Modules\Dian\Domain\CufeInput;
use App\Modules\Dian\Ubl\InvoiceXml;
use App\Modules\Dian\Ubl\XsdValidator;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Development check, in one run: the CUFE of the annex 1.9 example (section 11.2.1) against its
 * published result, and the XML of an issued demo invoice, built by the same service the app
 * uses, against the official UBL-Invoice-2.1.xsd. Read-only: nothing is stored.
 */
final class DianCheckCommand extends Command
{
    protected $signature = 'dian:check';

    protected $description = 'Check the CUFE against the annex example and validate the XML of an issued invoice against the official XSD';

    /** Annex 1.9, section 11.2.1. */
    private const string ANNEX_CUFE = '8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4';

    public function handle(InvoiceXml $builder, XsdValidator $validator, CurrentCompany $currentCompany): int
    {
        $cufeOk = $this->checkCufe();
        $xmlOk = $this->checkXml($builder, $validator, $currentCompany);

        $this->newLine();

        if ($cufeOk && $xmlOk) {
            $this->info('dian:check passed: CUFE matches the annex and the XML is valid against the official XSD.');

            return self::SUCCESS;
        }

        $this->error('dian:check failed.');

        return self::FAILURE;
    }

    private function checkCufe(): bool
    {
        $input = new CufeInput(
            number: '323200000129',
            issueDate: '2019-01-16',
            issueTime: '10:53:10-05:00',
            lineExtensionAmount: '1500000.00',
            ivaAmount: '285000.00',
            incAmount: '0.00',
            icaAmount: '0.00',
            payableAmount: '1785000.00',
            issuerNit: '700085371',
            customerId: '800199436',
            technicalKey: '693ff6f2a553c3646a063436fd4dd9ded0311471',
            environment: 1,
        );

        $cufe = Cufe::calculate($input);
        $ok = hash_equals(self::ANNEX_CUFE, $cufe);

        $this->line('1. CUFE of the annex 1.9 example (section 11.2.1)');
        $this->line('   Composition: '.Cufe::composition($input));
        $this->line('   Calculated:  '.$cufe);
        $this->line('   Expected:    '.self::ANNEX_CUFE);
        $ok ? $this->info('   PASS: the CUFE matches the annex.') : $this->error('   FAIL: the CUFE does not match the annex.');

        return $ok;
    }

    private function checkXml(InvoiceXml $builder, XsdValidator $validator, CurrentCompany $currentCompany): bool
    {
        $this->newLine();
        $this->line('2. XML of an issued demo invoice against the official XSD');

        // An issued invoice of any company, preferring one with withholdings and discounts so more
        // of the structure is exercised, acting as an owner of its company.
        $document = Document::withoutGlobalScope(CompanyScope::class)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Issued)
            ->whereNotNull('cufe')
            ->orderByRaw('withholding_total > 0 desc')
            ->orderByRaw('discount_total > 0 desc')
            ->orderByDesc('issued_at')
            ->first();

        if ($document === null) {
            $this->error('   FAIL: there is no issued invoice. Run make demo first.');

            return false;
        }

        $currentCompany->set(Membership::query()
            ->with('company')
            ->where('company_id', $document->company_id)
            ->where('role', Role::Owner)
            ->firstOrFail());

        $xml = $builder->build($document);
        $result = $validator->validateInvoice($xml);

        $this->line(sprintf('   Invoice:     %s of %s (%s bytes)', $document->full_number, $document->company_snapshot['legal_name'], number_format(strlen($xml))));
        $this->line('   CUFE:        '.$document->cufe);
        $this->line('   Schema:      '.str_replace(base_path().'/', '', $validator->schemaPath()));
        $this->line(sprintf('   Left out:    %d empty extension reserved for the signature (stage 5)', $result->skippedExtensions));

        if ($result->valid()) {
            $this->info('   PASS: the XML is valid against UBL-Invoice-2.1.xsd.');

            return true;
        }

        $this->error(sprintf('   FAIL: %d schema errors.', count($result->errors)));

        foreach (array_slice($result->errors, 0, 20) as $error) {
            $this->line('   - '.$error);
        }

        return false;
    }
}
