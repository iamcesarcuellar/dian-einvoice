<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\Sweeper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Safety net of the chain, run by the relay every half minute across companies: a document still
 * processing that has not changed for STALLED_SECONDS, is not waiting for a scheduled attempt and
 * has no unpublished event (its job was lost, such as with the Redis data) gets the event of its
 * step recorded again. Steps are idempotent, so a duplicate is harmless.
 */
final class StalledChainSweeper implements Sweeper
{
    public const int STALLED_SECONDS = 90;

    private const int LIMIT = 100;

    public function __construct(private readonly Outbox $outbox) {}

    public function sweep(): int
    {
        $threshold = Carbon::now()->subSeconds(self::STALLED_SECONDS);

        $documents = Document::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->whereIn('electronic_status', ElectronicStatus::processing())
            ->where('updated_at', '<', $threshold)
            ->where(fn (Builder $query) => $query
                ->whereNull('electronic_next_attempt_at')
                ->orWhere('electronic_next_attempt_at', '<', $threshold))
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->from('outbox_events')
                ->whereColumn('outbox_events.aggregate_id', 'documents.id')
                ->whereNull('outbox_events.published_at'))
            ->limit(self::LIMIT)
            ->get();

        foreach ($documents as $document) {
            [$type, $payload] = match ($document->electronic_status) {
                ElectronicStatus::Pending => [ChainEvents::GENERATE, []],
                ElectronicStatus::Generated => [ChainEvents::SIGN, []],
                ElectronicStatus::Signed => [ChainEvents::TRANSMIT, ['attempt' => 1]],
                default => $this->whileSending($document),
            };

            $this->outbox->record($document->company_id, 'document', $document->id, $type, [
                'document_id' => $document->getKey(),
                'run' => $document->electronic_run,
                'swept' => true,
                ...$payload,
            ]);
        }

        return $documents->count();
    }

    /**
     * While sending: record the answer of the last call if it got one, take over a call that never
     * finished, or else start the next attempt.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function whileSending(Document $document): array
    {
        $last = DianTransmission::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('document_id', $document->getKey())
            ->where('run', $document->electronic_run)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        return match (true) {
            $last !== null && $last->finished_at === null => [ChainEvents::TRANSMIT, ['attempt' => $document->electronic_attempt]],
            $last !== null && $last->is_valid !== null => [ChainEvents::RESPONSE_RECEIVED, ['transmission_id' => $last->getKey()]],
            default => [ChainEvents::TRANSMIT, ['attempt' => $document->electronic_attempt + 1]],
        };
    }
}
