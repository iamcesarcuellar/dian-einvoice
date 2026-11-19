<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Concepts of a credit note (annex 1.9, table 13.2.4 of the toolbox,
 * cac:DiscrepancyResponse/cbc:ResponseCode). Codes 5 and 6 are the prompt payment and the sales
 * volume discounts of the table; the genericode list ConceptoNotaCredito-2.1 of the toolbox is
 * older (its 5 is "Otros" and it has no 6), and the 2023 example of a note uses code 6.
 */
enum CreditNoteConcept: string
{
    use CodeList;

    case PartialReturn = '1';
    case Void = '2';
    case Discount = '3';
    case PriceAdjustment = '4';
    case PromptPaymentDiscount = '5';
    case VolumeDiscount = '6';

    public function label(): string
    {
        return match ($this) {
            self::PartialReturn => 'Partial return of goods or partial rejection of a service',
            self::Void => 'Void of the electronic invoice',
            self::Discount => 'Partial or total rebate or discount',
            self::PriceAdjustment => 'Price adjustment',
            self::PromptPaymentDiscount => 'Commercial discount for prompt payment',
            self::VolumeDiscount => 'Commercial discount for sales volume',
        };
    }

    /**
     * The description of table 13.2.4, as the DIAN writes it. It goes into
     * cac:DiscrepancyResponse/cbc:Description, which must correspond to the code (rule CBF04).
     */
    public function officialName(): string
    {
        return match ($this) {
            self::PartialReturn => 'Devolución parcial de los bienes y/o no aceptación parcial del servicio',
            self::Void => 'Anulación de factura electrónica',
            self::Discount => 'Rebaja o descuento parcial o total',
            self::PriceAdjustment => 'Ajuste de precio',
            self::PromptPaymentDiscount => 'Descuento comercial por pronto pago',
            self::VolumeDiscount => 'Descuento comercial por volumen de ventas',
        };
    }

    /**
     * @return list<array{code: string, name: string, official_name: string}>
     */
    public static function reference(): array
    {
        return array_map(fn (self $case): array => ['code' => $case->value, 'name' => $case->label(), 'official_name' => $case->officialName()], self::cases());
    }
}
