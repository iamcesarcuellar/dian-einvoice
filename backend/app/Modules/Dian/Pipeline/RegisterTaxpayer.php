<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Dian\Simulator\TaxpayerRegistry;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Outbox\ConsumesOutboxEvents;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Registers the company with the local DIAN simulator again after its DIAN settings or numbering
 * changed (event dian.taxpayer_changed). Idempotent: it sends the whole current data. If the
 * simulator is down the job retries; the transmission also registers before sending if needed.
 */
final class RegisterTaxpayer implements ConsumesOutboxEvents
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $companyId) {}

    public static function fromOutboxEvent(OutboxEvent $event): static
    {
        return new static($event->company_id);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(TaxpayerRegistry $registry, CurrentCompany $currentCompany): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company !== null) {
            $currentCompany->runAs($company, fn () => $registry->register($company));
        }
    }
}
