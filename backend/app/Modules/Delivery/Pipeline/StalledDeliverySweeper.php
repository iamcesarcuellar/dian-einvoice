<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\Sweeper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Safety net of the delivery, run by the relay every half minute across companies, as the one of
 * the electronic chain: a delivery still pending that has not changed for STALLED_SECONDS, is not
 * waiting for a scheduled attempt and has no unpublished event gets the event of its step recorded
 * again. Steps are idempotent, so a duplicate is harmless.
 */
final class StalledDeliverySweeper implements Sweeper
{
    public const int STALLED_SECONDS = 90;

    private const int LIMIT = 100;

    public function __construct(private readonly Outbox $outbox) {}

    public function sweep(): int
    {
        $threshold = Carbon::now()->subSeconds(self::STALLED_SECONDS);

        $documents = Document::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('delivery_status', DeliveryStatus::Pending)
            ->whereNotNull('delivery_step')
            ->where('updated_at', '<', $threshold)
            ->where(fn (Builder $query) => $query
                ->whereNull('delivery_next_attempt_at')
                ->orWhere('delivery_next_attempt_at', '<', $threshold))
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->from('outbox_events')
                ->whereColumn('outbox_events.aggregate_id', 'documents.id')
                ->whereNull('outbox_events.published_at'))
            ->limit(self::LIMIT)
            ->get();

        foreach ($documents as $document) {
            $step = $document->delivery_step;

            if (! $step instanceof DeliveryStep) {
                continue;
            }

            $payload = ['document_id' => $document->getKey(), 'delivery_run' => $document->delivery_run, 'swept' => true];

            if ($step === DeliveryStep::Email) {
                // Take over an attempt that never finished, or else start the next one.
                $unfinished = DeliveryAttempt::query()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->where('document_id', $document->getKey())
                    ->where('run', $document->delivery_run)
                    ->whereNull('finished_at')
                    ->exists();

                $payload['attempt'] = $unfinished ? $document->delivery_attempt : $document->delivery_attempt + 1;
            }

            $this->outbox->record($document->company_id, 'document', $document->id, DeliveryPipeline::eventFor($step), $payload);
        }

        return $documents->count();
    }
}
