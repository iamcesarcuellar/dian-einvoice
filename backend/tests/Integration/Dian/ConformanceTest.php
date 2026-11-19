<?php

namespace Tests\Integration\Dian;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Services\SigningCertificates;
use App\Modules\Dian\Ubl\XsdValidator;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\IntegrationTestCase;

/**
 * Every type of document the chain signs is valid against its official XSD, and its XAdES
 * signature is verified by xmlsec1, a tool independent of the code that signed it.
 */
final class ConformanceTest extends IntegrationTestCase
{
    public function test_invoice_credit_note_and_debit_note_are_valid_and_their_signatures_verify(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company, [
            ['description' => 'Synthetic service with IVA', 'quantity' => '2', 'unit_price' => '150000', 'discount_rate' => '10', 'tax_id' => $company->iva()],
            ['description' => 'Synthetic excluded line', 'quantity' => '1', 'unit_price' => '25000', 'tax_id' => null],
        ]);
        $documents = [
            $invoice,
            $this->runChain($this->issue($company, $this->draftNote($company, DocumentType::CreditNote, $invoice, '20000'))),
            $this->runChain($this->issue($company, $this->draftNote($company, DocumentType::DebitNote, $invoice, '5000'))),
        ];

        $directory = storage_path('framework/testing/xmlsec-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);
        $certificates = app(SigningCertificates::class);
        $certificate = $certificates->current();
        $this->assertNotNull($certificate);
        file_put_contents("{$directory}/certificate.pem", $certificates->credentials($certificate)->certificatePem);

        try {
            foreach ($documents as $document) {
                $this->assertSame(ElectronicStatus::Accepted, $document->electronic_status, "{$document->full_number} was not accepted.");
                $xml = app(DocumentStorage::class)->get((string) $document->xml_path);

                $schema = app(XsdValidator::class)->validateDocument($document->type, $xml);
                $this->assertTrue($schema->valid(), "{$document->full_number} is not valid against its XSD: ".implode(' ', $schema->errors));
                $this->assertSame(0, $schema->skippedExtensions);

                $this->assertTrue($this->verifies($directory, $document, $xml), "xmlsec1 does not verify the signature of {$document->full_number}.");
                $tampered = preg_replace('/(<cbc:PayableAmount currencyID="COP">)\d+/', '${1}1', $xml, 1);
                $this->assertFalse($this->verifies($directory, $document, (string) $tampered), "xmlsec1 verified a tampered copy of {$document->full_number}.");
            }
        } finally {
            File::deleteDirectory($directory);
        }

        $this->assertSame([DocumentType::Invoice, DocumentType::CreditNote, DocumentType::DebitNote], array_map(fn (Document $document): DocumentType => $document->type, $documents));
    }

    private function verifies(string $directory, Document $document, string $xml): bool
    {
        $file = "{$directory}/{$document->id}.xml";
        file_put_contents($file, $xml);
        $result = Process::run(['xmlsec1', '--verify', '--trusted-pem', "{$directory}/certificate.pem", $file]);

        return $result->successful() && str_contains($result->output().$result->errorOutput(), 'OK');
    }
}
