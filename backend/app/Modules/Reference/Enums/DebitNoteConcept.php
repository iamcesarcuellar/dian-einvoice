<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Concepts of a debit note (annex 1.9, table 13.2.5 of the toolbox,
 * cac:DiscrepancyResponse/cbc:ResponseCode).
 */
enum DebitNoteConcept: string
{
    use CodeList;

    case Interest = '1';
    case Expenses = '2';
    case ValueChange = '3';
    case Other = '4';

    public function label(): string
    {
        return match ($this) {
            self::Interest => 'Interest',
            self::Expenses => 'Expenses to collect',
            self::ValueChange => 'Change of the value',
            self::Other => 'Other',
        };
    }

    /**
     * The description of table 13.2.5, as the DIAN writes it. It goes into
     * cac:DiscrepancyResponse/cbc:Description, which must correspond to the code (rule DBF04).
     */
    public function officialName(): string
    {
        return match ($this) {
            self::Interest => 'Intereses',
            self::Expenses => 'Gastos por cobrar',
            self::ValueChange => 'Cambio del valor',
            self::Other => 'Otros',
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
