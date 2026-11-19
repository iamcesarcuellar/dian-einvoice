<?php

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Identity\Events\CompanyCreated;

/**
 * Every new company gets the numbering of its payment receipts: prefix RC, from 1, the default of
 * its type. It needs no DIAN resolution; it can be edited in Settings → Numbering.
 */
final class ProvisionReceiptNumbering
{
    public const string PREFIX = 'RC';

    public const int FROM = 1;

    public const int TO = 99_999_999;

    public function handle(CompanyCreated $event): void
    {
        // No current company exists yet, so the record names its company explicitly.
        self::create($event->company->id);
    }

    public static function create(string $companyId): NumberTemplate
    {
        return NumberTemplate::forceCreate([
            'company_id' => $companyId,
            'document_type' => NumberingType::Payment,
            'prefix' => self::PREFIX,
            'from_number' => self::FROM,
            'to_number' => self::TO,
            'next_number' => self::FROM,
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
