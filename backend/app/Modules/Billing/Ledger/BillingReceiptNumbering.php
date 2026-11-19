<?php

namespace App\Modules\Billing\Ledger;

use App\Modules\Billing\Enums\NumberingStatus;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Listeners\ProvisionReceiptNumbering;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Payments\Ports\ReceiptNumber;
use App\Modules\Payments\Ports\ReceiptNumbering;
use Illuminate\Validation\ValidationException;

/**
 * The receipt numbering kept with the other numbering ranges: the default range of type payment,
 * locked and advanced inside the transaction of the payment, as invoices take theirs. A company
 * created before receipts existed gets its RC range on its first payment.
 */
final class BillingReceiptNumbering implements ReceiptNumbering
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function next(): ReceiptNumber
    {
        $template = $this->lock() ?? $this->provision();

        $problem = match (true) {
            ! $template->is_active => 'The numbering of the payment receipts is inactive. Activate it in Settings → Numbering.',
            $template->status() === NumberingStatus::Exhausted => 'The numbering of the payment receipts has no numbers left. Add the next range in Settings → Numbering.',
            $template->status() === NumberingStatus::Expired => 'The numbering of the payment receipts expired. Add the next range in Settings → Numbering.',
            $template->status() === NumberingStatus::Upcoming => 'The numbering of the payment receipts is not valid yet.',
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['numbering' => [$problem]]);
        }

        $number = $template->next_number;
        $template->next_number = $number + 1;
        $template->save();

        return new ReceiptNumber($template->id, (string) ($template->prefix ?? ''), $number);
    }

    private function lock(): ?NumberTemplate
    {
        return NumberTemplate::query()
            ->where('document_type', NumberingType::Payment)
            ->where('is_default', true)
            ->lockForUpdate()
            ->first();
    }

    /** Creates the default range once, with the company row locked so two first payments cannot both create it. */
    private function provision(): NumberTemplate
    {
        Company::query()->whereKey($this->currentCompany->id())->lockForUpdate()->first();

        $template = $this->lock();

        if ($template === null) {
            ProvisionReceiptNumbering::create($this->currentCompany->id());
            $template = $this->lock();
        }

        return $template ?? throw ValidationException::withMessages(['numbering' => ['The numbering of the payment receipts could not be created.']]);
    }
}
