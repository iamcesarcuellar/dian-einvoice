<?php

namespace App\Modules\Dian\Services;

use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentTax;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Dian\Domain\Cude;
use App\Modules\Dian\Domain\CudeInput;
use App\Modules\Dian\Domain\Cufe;
use App\Modules\Dian\Domain\CufeInput;
use App\Modules\Dian\Domain\SoftwareSecurityCode;
use App\Modules\Dian\Models\DianSetting;
use Brick\Math\BigDecimal;
use LogicException;

/**
 * The DIAN values a document receives inside its issue transaction: the CUFE of an invoice (with
 * the technical key of its numbering) or the CUDE of a note (with the PIN of the software), both
 * kept in the cufe column; the software security code, the QR verification URL, the environment,
 * and the copy of the software data its XML needs later. It reads the document after its number,
 * totals, tax subtotals and copies are written.
 */
final class DocumentStamp
{
    /** DIAN codes of the taxes in the CUFE and the CUDE. */
    private const string IVA = '01';

    private const string INC = '04';

    private const string ICA = '03';

    /**
     * @return array<string, mixed> Attributes for the document.
     */
    public function stamp(Document $document, ?NumberTemplate $numbering, DianSetting $settings): array
    {
        $company = $document->company_snapshot;
        $customer = $document->customer_snapshot;
        $environment = $settings->environment;
        $taxes = $this->taxTotals($document);

        if ($document->isNote()) {
            $key = Cude::calculate(new CudeInput(
                number: $document->full_number,
                issueDate: $document->issue_date->toDateString(),
                issueTime: $document->issue_time,
                lineExtensionAmount: (string) $document->line_extension_amount,
                ivaAmount: $taxes[self::IVA] ?? '0',
                incAmount: $taxes[self::INC] ?? '0',
                icaAmount: $taxes[self::ICA] ?? '0',
                payableAmount: (string) $document->payable_amount,
                issuerNit: $company['identification_number'],
                customerId: $customer['identification_number'],
                softwarePin: (string) $settings->software_pin,
                environment: $environment->value,
            ));
        } else {
            if ($numbering === null) {
                throw new LogicException('An invoice needs its numbering to calculate the CUFE.');
            }

            $key = Cufe::calculate(new CufeInput(
                number: $document->full_number,
                issueDate: $document->issue_date->toDateString(),
                issueTime: $document->issue_time,
                lineExtensionAmount: (string) $document->line_extension_amount,
                ivaAmount: $taxes[self::IVA] ?? '0',
                incAmount: $taxes[self::INC] ?? '0',
                icaAmount: $taxes[self::ICA] ?? '0',
                payableAmount: (string) $document->payable_amount,
                issuerNit: $company['identification_number'],
                customerId: $customer['identification_number'],
                technicalKey: (string) $numbering->technical_key,
                environment: $environment->value,
            ));
        }

        return [
            'cufe' => $key,
            'software_security_code' => SoftwareSecurityCode::calculate($settings->software_id, $settings->software_pin, $document->full_number),
            'qr_url' => $environment->qrUrl($key),
            'dian_environment' => $environment->value,
            // The company runs its own registered software, so it is also the software provider.
            'dian_snapshot' => [
                'environment' => $environment->value,
                'software_id' => $settings->software_id,
                'software_provider' => [
                    'identification_number' => $company['identification_number'],
                    'check_digit' => $company['check_digit'],
                ],
            ],
        ];
    }

    /**
     * Tax amount of the document by DIAN code, summed over its rates.
     *
     * @return array<string, string>
     */
    private function taxTotals(Document $document): array
    {
        $totals = [];

        foreach ($document->taxes()->get() as $tax) {
            /** @var DocumentTax $tax */
            $totals[$tax->dian_code] = (string) BigDecimal::of($totals[$tax->dian_code] ?? '0')->plus($tax->tax_amount);
        }

        return $totals;
    }
}
