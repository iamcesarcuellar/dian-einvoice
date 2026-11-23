<?php

namespace Tests\Integration\Delivery;

use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Delivery\Support\QrContent;
use App\Modules\Dian\Enums\DianEnvironment;
use Tests\IntegrationTestCase;

/**
 * What the QR code of the PDF holds (annex 1.9, section 11.7): one "Field: value" line for each field
 * of the invoice, and the verification URL of section 11.7.1 last. The test reads the text that
 * QrSvg turns into the image: reading the image back would take a QR decoder, a dependency this
 * project does not add.
 */
final class QrContentTest extends IntegrationTestCase
{
    public function test_the_qr_code_holds_the_fields_of_the_annex_and_the_verification_url(): void
    {
        $company = $this->actingIn($this->company());
        $inc = Tax::query()->where('dian_code', '04')->first(['id'])?->id;

        // 100,000 at IVA 19 % and 2 × 50,000 at INC 8 %: the INC goes to ValOtroIm.
        $invoice = $this->issue($company, $this->draftInvoice($company, [
            ['description' => 'Synthetic consulting service', 'quantity' => '1', 'unit_price' => '100000', 'tax_id' => $company->iva()],
            ['description' => 'Synthetic lunch menu', 'quantity' => '2', 'unit_price' => '50000', 'tax_id' => $inc],
        ]));
        $lines = explode("\n", QrContent::for($invoice));
        $cufe = (string) $invoice->cufe;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{96}$/', $cufe);
        $this->assertSame([
            'NumFac: '.$invoice->full_number,
            'FecFac: '.$invoice->issue_date?->toDateString(),
            'HorFac: '.$invoice->issue_time,
            'NitFac: '.$company->company->identification_number,
            'DocAdq: '.Contact::FINAL_CONSUMER_NUMBER,
            'ValFac: 200000.00',
            'ValIva: 19000.00',
            'ValOtroIm: 8000.00',
            'ValTolFac: 227000.00',
            'CUFE: '.$cufe,
            'https://catalogo-vpfe-hab.dian.gov.co/document/searchqr?documentkey='.$cufe,
        ], $lines);

        // Dates and times as the annex writes them, and the URL the XML carries in sts:QRCode.
        $this->assertMatchesRegularExpression('/^FecFac: \d{4}-\d{2}-\d{2}$/', $lines[1]);
        $this->assertMatchesRegularExpression('/^HorFac: \d{2}:\d{2}:\d{2}-05:00$/', $lines[2]);
        $this->assertMatchesRegularExpression('/^NitFac: \d+$/', $lines[3]);
        $this->assertSame(DianEnvironment::Test->qrUrl($cufe), $invoice->qr_url);
        $this->assertSame($invoice->qr_url, end($lines));
    }
}
