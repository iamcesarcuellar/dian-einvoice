<?php

namespace App\Modules\Dian\Console;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Domain\Cufe;
use App\Modules\Dian\Domain\CufeInput;
use App\Modules\Dian\Services\SigningCertificates;
use App\Modules\Dian\Signing\XadesSigner;
use App\Modules\Dian\Ubl\InvoiceXml;
use App\Modules\Dian\Ubl\XsdValidator;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Development check, in one run:
 *  1. the CUFE of the annex 1.9 example (section 11.2.1) against its published result;
 *  2. the unsigned XML of an issued demo invoice against the official UBL-Invoice-2.1.xsd;
 *  3. that XML signed by our XAdES signer and verified by xmlsec1, a tool independent of our
 *     code, trusting only the company's test certificate;
 *  4. a copy of the signed XML with one character changed, which xmlsec1 must reject;
 *  5. the complete signed XML, signature included, against the official XSD.
 * Read-only: nothing is stored; the temporary files are removed at the end.
 */
final class DianCheckCommand extends Command
{
    protected $signature = 'dian:check';

    protected $description = 'Check the CUFE against the annex example, validate an issued invoice XML against the official XSD, and sign it and verify the signature with xmlsec1';

    /** Annex 1.9, section 11.2.1. */
    private const string ANNEX_CUFE = '8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4';

    /** The element whose value is changed in the tampered copy. */
    private const string TAMPERED_ELEMENT = '<cbc:PayableAmount currencyID="COP">';

    public function handle(InvoiceXml $builder, XsdValidator $validator, CurrentCompany $currentCompany, XadesSigner $signer, SigningCertificates $certificates): int
    {
        $results = [$this->checkCufe()];

        $document = $this->demoInvoice($currentCompany);

        if ($document === null) {
            $this->error('   FAIL: there is no issued invoice. Run make demo first.');
            $results[] = false;
        } else {
            $xml = $builder->build($document);
            $results[] = $this->checkUnsignedXml($document, $xml, $validator);
            $results = [...$results, ...$this->checkSignature($xml, $signer, $certificates, $validator)];
        }

        $this->newLine();

        if (! in_array(false, $results, true)) {
            $this->info('dian:check passed: CUFE matches the annex, the XML is valid against the official XSD, and xmlsec1 verifies its signature and rejects a tampered copy.');

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

        return $this->verdict($ok, 'the CUFE matches the annex.', 'the CUFE does not match the annex.');
    }

    /**
     * An issued invoice of any company, preferring one with withholdings and discounts so more of
     * the structure is exercised, acting as an owner of its company.
     */
    private function demoInvoice(CurrentCompany $currentCompany): ?Document
    {
        $document = Document::withoutGlobalScope(CompanyScope::class)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Issued)
            ->whereNotNull('cufe')
            ->orderByRaw('withholding_total > 0 desc')
            ->orderByRaw('discount_total > 0 desc')
            ->orderByDesc('issued_at')
            ->first();

        if ($document !== null) {
            $currentCompany->set(Membership::query()
                ->with('company')
                ->where('company_id', $document->company_id)
                ->where('role', Role::Owner)
                ->firstOrFail());
        }

        return $document;
    }

    private function checkUnsignedXml(Document $document, string $xml, XsdValidator $validator): bool
    {
        $result = $validator->validateInvoice($xml);

        $this->newLine();
        $this->line('2. Unsigned XML of an issued demo invoice against the official XSD');
        $this->line(sprintf('   Invoice:     %s of %s (%s bytes)', $document->full_number, $document->company_snapshot['legal_name'], number_format(strlen($xml))));
        $this->line('   CUFE:        '.$document->cufe);
        $this->line('   Schema:      '.str_replace(base_path().'/', '', $validator->schemaPath()));
        $this->line(sprintf('   Left out:    %d empty extension reserved for the signature', $result->skippedExtensions));

        return $this->schemaVerdict($result->valid(), $result->errors);
    }

    /**
     * Steps 3 to 5.
     *
     * @return list<bool>
     */
    private function checkSignature(string $xml, XadesSigner $signer, SigningCertificates $certificates, XsdValidator $validator): array
    {
        $this->newLine();
        $this->line('3. Signature by our XAdES-EPES signer, verified by xmlsec1');

        $certificate = $certificates->current();

        if ($certificate === null || ! $certificate->status()->usable()) {
            $this->error('   FAIL: the company has no usable signing certificate. Run make demo first.');

            return [false, false, false];
        }

        $credentials = $certificates->credentials($certificate);
        $signed = $signer->sign($xml, $credentials, Carbon::now());
        $tampered = $this->tamper($signed);

        $directory = sys_get_temp_dir().'/dian-check-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException("The temporary directory {$directory} could not be created.");
        }

        $files = [
            'certificate' => "{$directory}/certificate.pem",
            'signed' => "{$directory}/signed.xml",
            'tampered' => "{$directory}/tampered.xml",
        ];

        try {
            file_put_contents($files['certificate'], $credentials->certificatePem);
            file_put_contents($files['signed'], $signed);
            file_put_contents($files['tampered'], $tampered['xml']);

            $this->line('   Tool:        '.trim(Process::run(['xmlsec1', '--version'])->output()));
            $this->line(sprintf('   Certificate: %s, serial %s%s', $certificate->subject, $certificate->serial_number, $certificate->is_test ? ' (test certificate)' : ''));
            $this->line(sprintf('   Signed XML:  %s bytes', number_format(strlen($signed))));

            $verification = Process::run(['xmlsec1', '--verify', '--trusted-pem', $files['certificate'], $files['signed']]);
            $output = $this->output($verification->output().$verification->errorOutput());
            $this->line('   xmlsec1:     '.$output);
            $verified = $this->verdict(
                $verification->successful() && str_contains($output, 'OK'),
                'xmlsec1 verifies the signature, trusting only the test certificate.',
                'xmlsec1 does not verify the signature.',
            );

            $this->newLine();
            $this->line('4. Tampered copy of the signed XML');
            $this->line(sprintf('   Change:      cbc:PayableAmount %s became %s, one character', $tampered['before'], $tampered['after']));
            $rejection = Process::run(['xmlsec1', '--verify', '--trusted-pem', $files['certificate'], $files['tampered']]);
            $output = $this->output($rejection->output().$rejection->errorOutput());
            $this->line('   xmlsec1:     '.$output);
            $rejected = $this->verdict(
                $rejection->failed() && str_contains($output, 'FAIL'),
                'xmlsec1 rejects the tampered copy.',
                'xmlsec1 did not reject the tampered copy as a failed verification.',
            );
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($directory);
        }

        $this->newLine();
        $this->line('5. Complete signed XML, signature included, against the official XSD');
        $result = $validator->validateInvoice($signed);
        $this->line(sprintf('   Left out:    %d extensions', $result->skippedExtensions));
        $schema = $this->schemaVerdict($result->valid() && $result->skippedExtensions === 0, $result->errors);

        return [$verified, $rejected, $schema];
    }

    /**
     * The signed XML with the first digit of the payable amount changed; still well formed.
     *
     * @return array{xml: string, before: string, after: string}
     */
    private function tamper(string $xml): array
    {
        $position = strpos($xml, self::TAMPERED_ELEMENT);

        if ($position === false) {
            throw new RuntimeException('The signed XML has no payable amount to change.');
        }

        $index = $position + strlen(self::TAMPERED_ELEMENT);
        $end = strpos($xml, '<', $index) ?: $index;
        $before = substr($xml, $index, $end - $index);
        $digit = $xml[$index] === '1' ? '2' : '1';
        $tampered = substr_replace($xml, $digit, $index, 1);

        return ['xml' => $tampered, 'before' => $before, 'after' => $digit.substr($before, 1)];
    }

    /** xmlsec1 output on one line. */
    private function output(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * @param  list<string>  $errors
     */
    private function schemaVerdict(bool $ok, array $errors): bool
    {
        if ($ok) {
            return $this->verdict(true, 'the XML is valid against UBL-Invoice-2.1.xsd.', '');
        }

        $this->verdict(false, '', sprintf('%d schema errors.', count($errors)));

        foreach (array_slice($errors, 0, 20) as $error) {
            $this->line('   - '.$error);
        }

        return false;
    }

    private function verdict(bool $ok, string $pass, string $fail): bool
    {
        $ok ? $this->info('   PASS: '.$pass) : $this->error('   FAIL: '.$fail);

        return $ok;
    }
}
