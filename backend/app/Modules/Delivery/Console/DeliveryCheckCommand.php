<?php

namespace App\Modules\Delivery\Console;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\LocalMailbox\LocalMailbox;
use App\Modules\Delivery\LocalMailbox\LocalMailboxUnavailable;
use App\Modules\Delivery\Mail\DeliverySubject;
use App\Modules\Delivery\Services\DocumentFiles;
use App\Modules\Dian\Ubl\AttachedDocumentXml;
use App\Modules\Dian\Ubl\UblDocumentXml;
use App\Modules\Dian\Ubl\XsdValidator;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Console\LocalOnly;
use App\Support\Json;
use App\Support\Storage\DocumentStorage;
use App\Support\Xml\SafeXml;
use App\Support\Xml\XmlRefused;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Development check of the customer delivery, in one run, on an accepted demo invoice that was
 * delivered by email (the one with the most lines, so its PDF has more than one page):
 *  1. its stored AttachedDocument against the official UBL-AttachedDocument-2.1.xsd, signature
 *     included, and that it holds the signed invoice and the ApplicationResponse of the local
 *     simulator, byte for byte as stored;
 *  2. its stored PDF read by poppler (pdfinfo, pdftotext), tools independent of the one that wrote
 *     it: a valid PDF of at least one page that contains the invoice number and the CUFE;
 *  3. its email in the local mailbox, through the Mailpit API: to the customer, with the subject of
 *     annex 1.9 (section 9.1) and a single zip attached that holds the AttachedDocument and the PDF.
 * Read-only: nothing is stored; the temporary files are removed at the end.
 */
final class DeliveryCheckCommand extends Command
{
    protected $signature = 'delivery:check';

    protected $description = 'Validate the AttachedDocument of a delivered demo invoice against the official XSD, read its PDF with poppler and find its email with the zip in the local mailbox';

    /** NIT;name;number;type 01;trade name (annex 1.9, section 9.1). */
    private const string SUBJECT_PATTERN = '/^\d+;[^;]+;[A-Z0-9]+;01;[^;]+$/';

    public function handle(CurrentCompany $currentCompany, DocumentFiles $files, DocumentStorage $storage, XsdValidator $validator, LocalMailbox $mailbox): int
    {
        if (($refusal = LocalOnly::refusal($this->getName() ?? 'This command')) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $this->summary();

        $document = $this->deliveredInvoice($currentCompany);

        if ($document === null) {
            $this->error('   FAIL: there is no delivered invoice. Run make demo first.');

            return self::FAILURE;
        }

        $this->line(sprintf('Invoice:     %s of %s, %d lines, delivered to %s', $document->full_number, $document->companySnapshot()['legal_name'], $document->lines()->count(), $document->delivery_recipient));
        $this->line('CUFE:        '.$document->cufe);

        $results = [
            ...$this->checkAttachedDocument($document, $files, $storage, $validator),
            ...$this->checkPdf($document, $files),
            ...$this->checkEmail($document, $files, $mailbox),
        ];

        $this->newLine();

        if (! in_array(false, $results, true)) {
            $this->info('delivery:check passed: the AttachedDocument is valid against the official XSD and holds the signed invoice and the ApplicationResponse, poppler reads the PDF with its number and CUFE, and the local mailbox has the email with the annex subject and the zip.');

            return self::SUCCESS;
        }

        $this->error('delivery:check failed.');

        return self::FAILURE;
    }

    /** How the delivery of the accepted demo invoices ended, by status. */
    private function summary(): void
    {
        $counts = [];

        $rows = DB::table('documents')
            ->where('type', DocumentType::Invoice->value)
            ->where('electronic_status', ElectronicStatus::Accepted->value)
            ->select('delivery_status')
            ->selectRaw('count(*) as total')
            ->groupBy('delivery_status')
            ->get();

        foreach ($rows as $row) {
            $counts[is_string($row->delivery_status) ? $row->delivery_status : 'none'] = is_numeric($row->total) ? (int) $row->total : 0;
        }

        ksort($counts);
        $this->line('Accepted invoices by delivery status: '.(implode(', ', array_map(fn (string $status, int $total): string => "{$status} {$total}", array_keys($counts), $counts)) ?: 'none'));
        $this->newLine();
    }

    /** The delivered invoice with the most lines, acting as an owner of its company. */
    private function deliveredInvoice(CurrentCompany $currentCompany): ?Document
    {
        $document = Document::withoutGlobalScope(CompanyScope::class)
            ->where('type', DocumentType::Invoice)
            ->where('electronic_status', ElectronicStatus::Accepted)
            ->where('delivery_status', DeliveryStatus::Delivered)
            ->orderByDesc(DB::table('document_lines')->selectRaw('count(*)')->whereColumn('document_lines.document_id', 'documents.id'))
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

    /**
     * @return list<bool>
     */
    private function checkAttachedDocument(Document $document, DocumentFiles $files, DocumentStorage $storage, XsdValidator $validator): array
    {
        $this->newLine();
        $this->line('1. AttachedDocument against the official XSD, with the signed invoice and the ApplicationResponse inside');

        $file = $files->find($document, DocumentFileKind::AttachedDocument);

        if ($file === null || ! $storage->exists($file->path)) {
            return [$this->verdict(false, '', 'the AttachedDocument is not stored.')];
        }

        $xml = $files->contents($file);
        $this->line(sprintf('   File:        %s (%s bytes, SHA-256 %s)', $file->file_name, number_format($file->size), $file->sha256));
        $this->line('   Schema:      '.str_replace(base_path().'/', '', $validator->attachedDocumentSchemaPath()));

        $result = $validator->validateAttachedDocument($xml);
        $this->line(sprintf('   Left out:    %d extensions', $result->skippedExtensions));
        $schema = $this->verdict($result->valid() && $result->skippedExtensions === 0, 'valid against UBL-AttachedDocument-2.1.xsd, signature included.', sprintf('%d schema errors.', count($result->errors)));

        foreach (array_slice($result->errors, 0, 20) as $error) {
            $this->line('   - '.$error);
        }

        $xpath = new DOMXPath(SafeXml::load($xml));
        $xpath->registerNamespace('a', AttachedDocumentXml::NS_ATTACHED);
        $xpath->registerNamespace('cbc', UblDocumentXml::NS_CBC);
        $xpath->registerNamespace('cac', UblDocumentXml::NS_CAC);
        $xpath->registerNamespace('ds', UblDocumentXml::NS_DS);
        $xpath->registerNamespace('ext', UblDocumentXml::NS_EXT);

        $invoice = $this->text($xpath, 'string(/a:AttachedDocument/cac:Attachment/cac:ExternalReference/cbc:Description)');
        $response = $this->text($xpath, 'string(/a:AttachedDocument/cac:ParentDocumentLineReference/cac:DocumentReference/cac:Attachment/cac:ExternalReference/cbc:Description)');
        $signed = $this->number($xpath, 'count(//ds:Signature)') === 1
            && $this->number($xpath, 'count(/a:AttachedDocument/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/ds:Signature)') === 1;

        $this->line(sprintf('   Container:   ParentDocumentID %s, ValidatorID "%s", ValidationResultCode %s', $this->text($xpath, 'string(/a:AttachedDocument/cbc:ParentDocumentID)'), $this->text($xpath, 'string(//cac:ResultOfVerification/cbc:ValidatorID)'), $this->text($xpath, 'string(//cac:ResultOfVerification/cbc:ValidationResultCode)')));

        $invoiceInside = $this->embedded($invoice, 'Invoice', $document);
        $sameInvoice = $document->xml_path !== null && $invoice === $storage->get($document->xml_path);
        $this->line(sprintf('   Invoice:     %s, %s bytes, %s', $invoiceInside ?? 'not an invoice', number_format(strlen($invoice)), $sameInvoice ? 'identical to the stored signed XML' : 'different from the stored signed XML'));
        $hasInvoice = $this->verdict($invoiceInside !== null && $sameInvoice && str_contains($invoice, '<ds:Signature'), 'it holds the signed invoice, byte for byte.', 'it does not hold the signed invoice as stored.');

        $responseInside = $this->embedded($response, 'ApplicationResponse', $document);
        $sameResponse = $document->dian_response_path !== null && $response === $storage->get($document->dian_response_path);
        $this->line(sprintf('   Response:    %s, %s bytes, %s', $responseInside ?? 'not an ApplicationResponse', number_format(strlen($response)), $sameResponse ? 'identical to the local simulator\'s stored response' : 'different from the stored response'));
        $hasResponse = $this->verdict($responseInside !== null && $sameResponse, 'it holds the local simulator\'s ApplicationResponse, byte for byte.', 'it does not hold the ApplicationResponse as stored.');

        $this->line('   Signature:   '.($signed ? 'one ds:Signature of the issuer in its UBLExtensions' : 'missing'));

        return [$schema, $hasInvoice, $hasResponse && $signed];
    }

    /**
     * Root element, ID and CUFE of an XML embedded as CDATA, when it is the expected document of
     * this invoice; null otherwise.
     */
    private function embedded(string $xml, string $root, Document $document): ?string
    {
        try {
            $dom = SafeXml::load($xml);
        } catch (XmlRefused) {
            return null;
        }

        if ($dom->documentElement?->localName !== $root) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('cbc', UblDocumentXml::NS_CBC);
        $xpath->registerNamespace('cac', UblDocumentXml::NS_CAC);

        $key = $root === 'Invoice'
            ? $this->text($xpath, 'string(/*/cbc:UUID)')
            : $this->text($xpath, 'string(/*/cac:DocumentResponse/cac:DocumentReference/cbc:UUID)');
        $id = $this->text($xpath, 'string(/*/cbc:ID)');

        if ($key !== $document->cufe || ($root === 'Invoice' && $id !== $document->full_number)) {
            return null;
        }

        return "{$root} {$id} with the CUFE of the invoice";
    }

    /**
     * @return list<bool>
     */
    private function checkPdf(Document $document, DocumentFiles $files): array
    {
        $this->newLine();
        $this->line('2. PDF read by poppler, a tool independent of the one that wrote it');

        $file = $files->find($document, DocumentFileKind::Pdf);

        if ($file === null) {
            return [$this->verdict(false, '', 'the PDF is not stored.')];
        }

        $pdf = $files->contents($file);
        $this->line(sprintf('   File:        %s (%s bytes, %d pages, SHA-256 %s)', $file->file_name, number_format(strlen($pdf)), (int) $file->pages, $file->sha256));

        return $this->withTemporary(['document.pdf' => $pdf], function (array $paths) use ($document, $pdf): array {
            $info = Process::run(['pdfinfo', $paths['document.pdf']]);
            preg_match('/^Pages:\s+(\d+)/m', $info->output(), $pages);
            preg_match('/^Page size:\s+(.+)$/m', $info->output(), $size);
            $pageCount = (int) ($pages[1] ?? 0);

            $this->line(sprintf('   pdfinfo:     %s, %d pages, %s', $info->successful() ? 'parsed' : 'error: '.trim($info->errorOutput()), $pageCount, trim($size[1] ?? 'unknown size')));

            $fonts = Process::run(['pdffonts', $paths['document.pdf']]);
            $names = array_values(array_unique(array_map(fn (string $line): string => preg_replace('/^[A-Z]{6}\+/', '', strtok($line, ' ') ?: '') ?? '', array_slice(preg_split('/\R/', trim($fonts->output())) ?: [], 2))));
            $this->line('   Fonts:       '.(implode(', ', $names) ?: 'none listed'));

            $valid = $this->verdict(
                $info->successful() && str_starts_with($pdf, '%PDF-') && str_contains(substr($pdf, -1024), '%%EOF') && $pageCount >= 1,
                "a valid PDF of {$pageCount} pages.",
                'not a valid PDF of at least one page.',
            );

            $text = Process::run(['pdftotext', '-layout', $paths['document.pdf'], '-']);
            $plain = $text->output();
            $compact = preg_replace('/\s+/', '', $plain) ?? $plain;
            $hasNumber = str_contains($plain, (string) $document->full_number);
            $hasCufe = str_contains($compact, (string) $document->cufe);

            $this->line(sprintf('   pdftotext:   %s characters; number %s, CUFE %s', number_format(mb_strlen($plain)), $hasNumber ? 'found' : 'missing', $hasCufe ? 'found' : 'missing'));
            $content = $this->verdict($text->successful() && $hasNumber && $hasCufe, 'its text contains the invoice number and the full CUFE.', 'its text lacks the invoice number or the CUFE.');

            return [$valid, $content];
        });
    }

    /**
     * @return list<bool>
     */
    private function checkEmail(Document $document, DocumentFiles $files, LocalMailbox $mailbox): array
    {
        $this->newLine();
        $this->line('3. Email in the local mailbox (Mailpit API)');

        $expected = DeliverySubject::for($document);
        $recipient = (string) $document->delivery_recipient;
        $this->line('   Expected:    to '.$recipient.', subject "'.$expected.'"');

        try {
            $summaries = $mailbox->search('to:"'.$recipient.'"', 200);
            $summary = collect($summaries)->first(fn (array $message): bool => ($message['Subject'] ?? null) === $expected);

            if ($summary === null) {
                return [$this->verdict(false, '', sprintf('no email to %s with that subject among %d to that address.', $recipient, count($summaries)))];
            }

            $message = $mailbox->message(Json::text($summary, 'ID') ?? '');
        } catch (LocalMailboxUnavailable $exception) {
            return [$this->verdict(false, '', 'the local mailbox is not responding: '.$exception->getMessage())];
        }

        $to = array_map(fn (array $address): string => Json::text($address, 'Address') ?? '', Json::objects($message, 'To'));
        $replyTo = array_map(fn (array $address): string => Json::text($address, 'Address') ?? '', Json::objects($message, 'ReplyTo'));
        $this->line(sprintf('   Found:       message %s, from "%s" <%s>, reply to %s, %s', Json::text($message, 'ID') ?? '?', Json::text($message, 'From.Name') ?? '', Json::text($message, 'From.Address') ?? '', implode(', ', $replyTo) ?: 'none', Json::text($message, 'Date') ?? ''));

        $addressed = $this->verdict(
            $to === [$recipient] && ($message['Subject'] ?? null) === $expected && preg_match(self::SUBJECT_PATTERN, $expected) === 1,
            'to the customer, with the subject of annex 1.9 (NIT;name;number;01;trade name).',
            'the recipient or the subject is not the expected one.',
        );

        $attachments = Json::objects($message, 'Attachments');
        $this->line(sprintf('   Attachments: %s', implode(', ', array_map(fn (array $part): string => sprintf('%s (%s, %s bytes)', Json::text($part, 'FileName') ?? '?', Json::text($part, 'ContentType') ?? '?', number_format(Json::int($part, 'Size') ?? 0)), $attachments)) ?: 'none'));

        $package = $files->find($document, DocumentFileKind::Package);
        $attached = $files->find($document, DocumentFileKind::AttachedDocument);
        $pdf = $files->find($document, DocumentFileKind::Pdf);

        if (count($attachments) !== 1 || $package === null || $attached === null || $pdf === null || ($attachments[0]['FileName'] ?? null) !== $package->file_name) {
            return [$addressed, $this->verdict(false, '', 'the email does not carry exactly one attachment, the invoice\'s zip.')];
        }

        try {
            $zip = $mailbox->part(Json::text($message, 'ID') ?? '', Json::text($attachments[0], 'PartID') ?? '');
        } catch (LocalMailboxUnavailable $exception) {
            return [$addressed, $this->verdict(false, '', 'the attachment could not be downloaded: '.$exception->getMessage())];
        }

        return [$addressed, ...$this->withTemporary(['package.zip' => $zip], function (array $paths) use ($files, $attached, $pdf): array {
            $archive = new ZipArchive;

            if ($archive->open($paths['package.zip']) !== true) {
                return [$this->verdict(false, '', 'the attachment is not a zip.')];
            }

            $entries = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = (string) $archive->getNameIndex($index);
                $entries[$name] = hash('sha256', (string) $archive->getFromIndex($index));
            }

            $archive->close();

            $this->line('   Zip:         '.implode(', ', array_keys($entries)));

            $expected = [
                $attached->file_name => hash('sha256', $files->contents($attached)),
                $pdf->file_name => hash('sha256', $files->contents($pdf)),
            ];

            return [$this->verdict(
                $entries === $expected,
                sprintf('one zip with the AttachedDocument %s and the PDF %s, identical to the stored ones.', $attached->file_name, $pdf->file_name),
                'the zip does not hold exactly the stored AttachedDocument and PDF.',
            )];
        })];
    }

    /**
     * Runs the callback with the files written to a temporary directory, removed afterwards.
     *
     * @template T
     *
     * @param  array<string, string>  $files  Name => contents.
     * @param  callable(array<string, string>): T  $callback
     * @return T
     */
    private function withTemporary(array $files, callable $callback): mixed
    {
        $directory = sys_get_temp_dir().'/delivery-check-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException("The temporary directory {$directory} could not be created.");
        }

        $paths = [];

        try {
            foreach ($files as $name => $contents) {
                $paths[$name] = "{$directory}/{$name}";
                file_put_contents($paths[$name], $contents);
            }

            return $callback($paths);
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            try {
                rmdir($directory);
            } catch (Throwable) {
                // Left in the temporary directory of the container; harmless.
            }
        }
    }

    /** The text of an XPath string() expression. */
    private function text(DOMXPath $xpath, string $expression): string
    {
        $value = $xpath->evaluate($expression);

        return is_string($value) ? $value : '';
    }

    /** The number of an XPath count() expression. */
    private function number(DOMXPath $xpath, string $expression): int
    {
        $value = $xpath->evaluate($expression);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function verdict(bool $ok, string $pass, string $fail): bool
    {
        $ok ? $this->info('   PASS: '.$pass) : $this->error('   FAIL: '.$fail);

        return $ok;
    }
}
