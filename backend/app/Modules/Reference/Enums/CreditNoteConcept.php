<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;
use App\Modules\Reference\Support\DianCodeList;

/**
 * Concepts of a credit note (annex 1.9, table 13.2.4; the official list ConceptoNotaCredito-2.1
 * of the toolbox, copied in resources/dian/codelists). It goes into
 * cac:DiscrepancyResponse/cbc:ResponseCode.
 */
enum CreditNoteConcept: string
{
    use CodeList;

    case PartialReturn = '1';
    case Void = '2';
    case Discount = '3';
    case PriceAdjustment = '4';
    case Other = '5';

    public const string LIST = 'ConceptoNotaCredito-2.1';

    public function label(): string
    {
        return match ($this) {
            self::PartialReturn => 'Partial return of goods or partial rejection of a service',
            self::Void => 'Void of the electronic invoice',
            self::Discount => 'Partial or total rebate or discount',
            self::PriceAdjustment => 'Price adjustment',
            self::Other => 'Other',
        };
    }

    /** The name in the official list of the DIAN. */
    public function officialName(): string
    {
        return DianCodeList::names(self::LIST)[$this->value] ?? $this->label();
    }

    /**
     * @return list<array{code: string, name: string, official_name: string}>
     */
    public static function reference(): array
    {
        return array_map(fn (self $case): array => ['code' => $case->value, 'name' => $case->label(), 'official_name' => $case->officialName()], self::cases());
    }
}
