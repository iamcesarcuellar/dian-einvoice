<?php

namespace Tests\Integration\Delivery;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Delivery\Mail\DeliverySubject;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Delivery\Pipeline\SendDeliveryEmail;
use App\Modules\Delivery\Services\DocumentFiles;
use App\Modules\Dian\Ubl\XsdValidator;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\IntegrationTestCase;
use Tests\Support\ScriptedMailTransport;
use ZipArchive;

/**
 * The customer delivery that follows an acceptance: AttachedDocument, PDF and the email with both
 * in a zip (annex 1.9, section 9). Here the email reaches Laravel's array mailer; make
 * delivery-check covers the SMTP leg to the local mailbox.
 */
final class DeliveryTest extends IntegrationTestCase
{
    public function test_an_accepted_invoice_is_delivered_with_its_attached_document_pdf_and_email(): void
    {
        $company = $this->company();
        $customer = $this->text($this->signedIn($company->user, $company)->postJson('/api/v1/contacts', [
            'organization_type' => '1',
            'identification_type' => '31',
            'identification_number' => '900123456',
            'legal_name' => 'Synthetic Customer SAS',
            'tax_scheme' => '01',
            'fiscal_responsibilities' => ['O-48'],
            'email' => 'synthetic-customer@example.com',
            'address' => 'Calle 10 # 43-20',
            'municipality_code' => '05001',
        ])->assertCreated()->json('data.id'));

        $invoice = $this->acceptedInvoice($company, header: ['contact_id' => $customer]);

        $this->assertSame(DeliveryStatus::Delivered, $invoice->delivery_status);
        $this->assertSame('synthetic-customer@example.com', $invoice->delivery_recipient);
        $files = app(DocumentFiles::class);

        $attached = $files->find($invoice, DocumentFileKind::AttachedDocument);
        $this->assertNotNull($attached);
        $xml = $files->contents($attached);
        $schema = app(XsdValidator::class)->validateAttachedDocument($xml);
        $this->assertTrue($schema->valid(), 'The AttachedDocument is not valid against its XSD: '.implode(' ', $schema->errors));
        $this->assertStringContainsString((string) $invoice->cufe, $xml);
        $this->assertStringContainsString('ApplicationResponse', $xml);

        $pdf = $files->find($invoice, DocumentFileKind::Pdf);
        $this->assertNotNull($pdf);
        $text = $this->pdfText($files->contents($pdf));
        $this->assertStringContainsString((string) $invoice->full_number, $text);
        $this->assertStringContainsString(substr((string) $invoice->cufe, 0, 24), str_replace([' ', "\n"], '', $text));

        $email = $this->onlyEmail();
        $this->assertSame(['synthetic-customer@example.com'], array_map(fn ($address): string => $address->getAddress(), $email->getTo()));
        $this->assertSame(DeliverySubject::for($invoice), $email->getSubject());
        $this->assertStringStartsWith($company->company->identification_number.';', (string) $email->getSubject());
        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame([$attached->file_name, $pdf->file_name], $this->zipEntries($attachments[0]->getBody()));
    }

    public function test_a_customer_without_email_gets_the_files_but_no_email(): void
    {
        $company = $this->company();

        // The final consumer, which every company has, has no email.
        $invoice = $this->acceptedInvoice($company);

        $this->assertSame(DeliveryStatus::NotApplicable, $invoice->delivery_status);
        $this->assertNotNull(app(DocumentFiles::class)->find($invoice, DocumentFileKind::AttachedDocument));
        $this->assertNotNull(app(DocumentFiles::class)->find($invoice, DocumentFileKind::Pdf));
        $this->assertCount(0, $this->transport()->messages());
    }

    public function test_a_temporary_smtp_failure_is_retried_and_a_permanent_reply_is_not(): void
    {
        Mail::extend('scripted', fn (): ScriptedMailTransport => new ScriptedMailTransport);
        config(['mail.mailers.scripted' => ['transport' => 'scripted'], 'mail.default' => 'scripted']);
        ScriptedMailTransport::$sent = [];
        $company = $this->company();
        $customer = $this->text($this->signedIn($company->user, $company)->postJson('/api/v1/contacts', [
            'organization_type' => '1',
            'identification_type' => '31',
            'identification_number' => '900123456',
            'legal_name' => 'Synthetic Customer SAS',
            'tax_scheme' => '01',
            'fiscal_responsibilities' => ['O-48'],
            'email' => 'synthetic-customer@example.com',
            'municipality_code' => '05001',
        ])->assertCreated()->json('data.id'));

        // 451 (try later) and a server that does not answer are retried; then it goes through.
        ScriptedMailTransport::$script = [451, 0];
        $delivered = $this->acceptedInvoice($company, header: ['contact_id' => $customer]);
        $this->assertSame(DeliveryStatus::Delivered, $delivered->delivery_status);
        $this->assertSame([EmailOutcome::Failed, EmailOutcome::Failed, EmailOutcome::Sent], $this->outcomes($delivered->id));
        $this->assertCount(1, ScriptedMailTransport::$sent);

        // 550 (no such mailbox) is final: one attempt, and the delivery fails.
        ScriptedMailTransport::$script = [550];
        $refused = $this->acceptedInvoice($company, header: ['contact_id' => $customer]);
        $this->assertSame(DeliveryStatus::Failed, $refused->delivery_status);
        $this->assertSame([EmailOutcome::Rejected], $this->outcomes($refused->id));
        $this->assertStringContainsString('550', $this->text(DeliveryAttempt::query()->where('document_id', $refused->id)->value('error')));

        // Its dead letter, moved back once the mailbox exists: the email step starts again, to the same customer.
        app()->call([new SendDeliveryEmail($company->company->id, $refused->id, 1, 1), 'handle']);
        $this->assertSame([DeliveryStatus::Pending, 2], [$refused->refresh()->delivery_status, $refused->delivery_run]);
        $refused = $this->runChain($refused);
        $this->assertSame(DeliveryStatus::Delivered, $refused->delivery_status);
        $this->assertSame([EmailOutcome::Rejected, EmailOutcome::Sent], $this->outcomes($refused->id));
        $this->assertCount(2, ScriptedMailTransport::$sent);
    }

    /**
     * The outcome of each email attempt of a document, oldest first.
     *
     * @return list<EmailOutcome|null>
     */
    private function outcomes(string $documentId): array
    {
        return array_values(DeliveryAttempt::query()->where('document_id', $documentId)->orderBy('attempt')->get()->map(fn (DeliveryAttempt $attempt): ?EmailOutcome => $attempt->outcome)->all());
    }

    private function transport(): ArrayTransport
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    private function onlyEmail(): Email
    {
        $messages = $this->transport()->messages();
        $this->assertCount(1, $messages);
        $message = $messages->first();
        $this->assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    /** The text of a PDF, read by poppler, a tool independent of the one that wrote it. */
    private function pdfText(string $pdf): string
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $file = storage_path('framework/testing/'.bin2hex(random_bytes(6)).'.pdf');
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $pdf);

        try {
            $info = Process::run(['pdfinfo', $file]);
            $this->assertTrue($info->successful(), 'pdfinfo cannot read the PDF: '.$info->errorOutput());
            $this->assertMatchesRegularExpression('/^Pages:\s+[1-9]/m', $info->output());

            return Process::run(['pdftotext', '-layout', $file, '-'])->throw()->output();
        } finally {
            File::delete($file);
        }
    }

    /**
     * @return list<string>
     */
    private function zipEntries(string $zip): array
    {
        $file = storage_path('framework/testing/'.bin2hex(random_bytes(6)).'.zip');
        file_put_contents($file, $zip);
        $archive = new ZipArchive;

        try {
            $this->assertTrue($archive->open($file) === true, 'The attachment is not a zip.');
            $names = [];

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $names[] = (string) $archive->getNameIndex($index);
            }

            $archive->close();
            sort($names);

            return $names;
        } finally {
            File::delete($file);
        }
    }
}
