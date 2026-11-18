<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;
use App\Modules\Reference\Support\DianCodeList;

/**
 * Concepts of a debit note (annex 1.9, table 13.2.5; the official list ConceptoNotaDebito-2.1 of
 * the toolbox, copied in resources/dian/codelists). It goes into
 * cac:DiscrepancyResponse/cbc:ResponseCode.
 */
enum DebitNoteConcept: string
{
    use CodeList;

    case Interest = '1';
    case Expenses = '2';
    case ValueChange = '3';
    case Other = '4';

    public const string LIST = 'ConceptoNotaDebito-2.1';

    public function label(): string
    {
        return match ($this) {
            self::Interest => 'Interest',
            self::Expenses => 'Expenses to collect',
            self::ValueChange => 'Change of the value',
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
