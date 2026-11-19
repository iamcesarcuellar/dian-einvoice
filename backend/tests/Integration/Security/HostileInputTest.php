<?php

namespace Tests\Integration\Security;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Services\DocumentFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\IntegrationTestCase;

/**
 * What users type or upload that could turn into something else: a certificate upload too big, not
 * a certificate or with a wrong password (which never comes back in an answer); HTML in a customer
 * name, printed as text in the PDF without loading anything; and line breaks in the data of a
 * company or an address, which never become email headers.
 */
final class HostileInputTest extends IntegrationTestCase
{
    public function test_a_certificate_upload_is_limited_checked_for_real_and_never_echoes_its_password(): void
    {
        $company = $this->company();
        $password = Str::random(24);
        $upload = fn (string $contents, ?string $with = null, string $name = 'signing.p12') => $this->signedIn($company->user, $company)
            ->post('/api/v1/dian-settings/certificate', ['file' => UploadedFile::fake()->createWithContent($name, $contents), 'password' => $with ?? $password]);

        $upload(random_bytes(101 * 1024))->assertStatus(422)->assertJsonValidationErrors('file');
        $upload('not a certificate', name: 'signing.pem')->assertStatus(422)->assertJsonValidationErrors('file');

        $refusals = [
            'not PKCS#12' => $upload(random_bytes(2048)),
            'wrong password' => $upload($this->pkcs12(Str::random(24))),
        ];

        foreach ($refusals as $case => $response) {
            $response->assertStatus(422)->assertJsonValidationErrors('file');
            $this->assertStringNotContainsString($password, (string) $response->getContent(), $case);
        }

        $upload($this->pkcs12($password))->assertCreated()->assertJsonMissingPath('data.password');
    }

    public function test_html_in_a_customer_name_is_printed_as_text_in_the_pdf(): void
    {
        $company = $this->company();
        $name = 'Synthetic <b>Bold</b> & <img src="http://127.0.0.1:9/pixel.png"> SAS';
        $customer = $this->text($this->signedIn($company->user, $company)->postJson('/api/v1/contacts', [
            'organization_type' => '1',
            'identification_type' => '31',
            'identification_number' => '900123456',
            'legal_name' => $name,
            'tax_scheme' => '01',
            'fiscal_responsibilities' => ['O-48'],
            'email' => 'synthetic-html@example.com',
            'address' => 'Calle 10 # 43-20',
            'municipality_code' => '05001',
        ])->assertCreated()->json('data.id'));

        $invoice = $this->acceptedInvoice($company, header: ['contact_id' => $customer, 'notes' => 'Synthetic <script>alert(1)</script> note']);
        $files = app(DocumentFiles::class);
        $pdf = $files->find($invoice, DocumentFileKind::Pdf);
        $this->assertNotNull($pdf);

        $text = preg_replace('/\s+/', ' ', $this->pdfText($files->contents($pdf))) ?? '';
        $this->assertStringContainsString('Synthetic <b>Bold</b> & <img src="http://127.0.0.1:9/pixel.png"> SAS', $text);
        $this->assertStringContainsString('<script>alert(1)</script>', $text);
    }

    public function test_line_breaks_in_company_data_or_an_address_never_become_email_headers(): void
    {
        $company = $this->company();
        $company->company->update(['legal_name' => "Synthetic Company SAS\r\nBcc: attacker@example.com"]);
        $api = $this->signedIn($company->user, $company);
        $customer = $this->text($api->postJson('/api/v1/contacts', [
            'organization_type' => '1',
            'identification_type' => '31',
            'identification_number' => '900654321',
            'legal_name' => 'Synthetic Customer SAS',
            'tax_scheme' => '01',
            'fiscal_responsibilities' => ['O-48'],
            'email' => 'synthetic-person@example.com',
            'municipality_code' => '05001',
        ])->assertCreated()->json('data.id'));

        $invoice = $this->acceptedInvoice($company, header: ['contact_id' => $customer]);
        $this->assertSame(DeliveryStatus::Delivered, $invoice->delivery_status);

        $message = $this->transport()->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $headers = explode("\r\n\r\n", $message->toString(), 2)[0];

        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $headers);
        $this->assertStringNotContainsString("\r", (string) $email->getSubject());
        $this->assertSame(['synthetic-person@example.com'], array_map(fn ($address): string => $address->getAddress(), $email->getTo()));

        // Send again to an address with a header in it: refused before any mailer.
        $api->postJson("/api/v1/invoices/{$invoice->id}/deliver", ['email' => "synthetic-person@example.com\r\nBcc: attacker@example.com"])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** A PKCS#12 bundle with a synthetic RSA key and a self-signed certificate. */
    private function pkcs12(string $password): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        // openssl_csr_new() takes the key by reference.
        $requestKey = $key;
        $request = openssl_csr_new(['commonName' => 'Synthetic Upload Test'], $requestKey);
        $this->assertNotFalse($request);
        $this->assertNotTrue($request);
        $certificate = openssl_csr_sign($request, null, $key, 30);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkcs12_export($certificate, $bundle, $key, $password));
        $this->assertIsString($bundle);

        return $bundle;
    }

    private function transport(): ArrayTransport
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }

    /** The text of a PDF, read by poppler, a tool independent of the one that wrote it. */
    private function pdfText(string $pdf): string
    {
        $this->assertStringStartsWith('%PDF-', $pdf);
        $file = storage_path('framework/testing/'.bin2hex(random_bytes(6)).'.pdf');
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $pdf);

        try {
            return Process::run(['pdftotext', '-raw', $file, '-'])->throw()->output();
        } finally {
            File::delete($file);
        }
    }
}
