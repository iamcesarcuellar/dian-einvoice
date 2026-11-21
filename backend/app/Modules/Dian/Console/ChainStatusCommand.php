<?php

namespace App\Modules\Dian\Console;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Support\Json;
use App\Support\Observability\Redactor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * An operation command of the runbooks (docs/runbooks/dian-errors.md, dead-letters.md, queue-backlog.md): the
 * electronic documents in the chain across companies, read only. What is in the chain now and what waits for a retry,
 * what failed in the last hours and why, the deliveries, and how the calls to the DIAN of the last minutes ended. With
 * --document, the chain of one document and its calls. It prints internal ids, numbers, states and masked reasons,
 * never a customer, an amount or a file.
 *
 *   make cloud-artisan ARGS="chain:status"
 *   make cloud-artisan ARGS="chain:status --document=<id>"
 */
final class ChainStatusCommand extends Command
{
    protected $signature = 'chain:status
        {--document= : One document, by its id (the document_id of a log line)}
        {--company= : The documents of one company only, by its id}
        {--hours=24 : The window of the failures}
        {--minutes=15 : The window of the calls to the DIAN}';

    protected $description = 'The electronic documents in the chain across companies: in progress, waiting for a retry, failed, and the last calls to the DIAN (an operation command of the runbooks)';

    public function handle(): int
    {
        $document = $this->option('document');

        if (is_string($document) && $document !== '') {
            return $this->document($document);
        }

        $company = $this->option('company');
        $company = is_string($company) && $company !== '' ? $company : null;
        $hours = max(1, (int) $this->option('hours'));
        $minutes = max(1, (int) $this->option('minutes'));
        $now = Carbon::now();
        $documents = fn (): Builder => Document::query()->withoutGlobalScope(CompanyScope::class)->when($company !== null, fn (Builder $query) => $query->where('company_id', $company));

        $this->line(sprintf('The electronic document chain%s, at %s:', $company === null ? ', across companies' : " of the company {$company}", $now->toIso8601ZuluString()));

        // In the chain now.
        $processing = $documents()->whereIn('electronic_status', ElectronicStatus::processing())->selectRaw('electronic_status, count(*) as documents')->groupBy('electronic_status')->pluck('documents', 'electronic_status');
        $waiting = $documents()->where('electronic_status', ElectronicStatus::Sending)->whereNotNull('electronic_next_attempt_at')->count();
        $next = $documents()->where('electronic_status', ElectronicStatus::Sending)->min('electronic_next_attempt_at');
        $oldest = $documents()->whereIn('electronic_status', ElectronicStatus::processing())->min('electronic_status_at');
        $this->line(sprintf(
            '  in the chain now: %s%s.',
            implode(', ', array_map(fn (ElectronicStatus $status): string => sprintf('%s %d', $status->value, is_numeric($processing[$status->value] ?? null) ? (int) $processing[$status->value] : 0), ElectronicStatus::processing())),
            $waiting === 0 ? '' : sprintf('; %d waiting for a retry of the transmission, the next at %s', $waiting, is_string($next) ? Carbon::parse($next)->toIso8601ZuluString() : '?'),
        ));

        if (is_string($oldest)) {
            $this->line(sprintf('  the oldest in the chain changed state %d s ago.', (int) Carbon::parse($oldest)->diffInSeconds($now)));
        }

        // Failed in the window, by step, with the most frequent reasons.
        $since = $now->copy()->subHours($hours);
        $failed = $documents()->where('electronic_status', ElectronicStatus::Failed)->where('electronic_status_at', '>=', $since)->selectRaw('electronic_failed_step, count(*) as documents')->groupBy('electronic_failed_step')->pluck('documents', 'electronic_failed_step');
        $this->line(sprintf('  failed in the last %d hours: %s.', $hours, $failed->isEmpty() ? 'none' : $failed->map(fn (mixed $count, string $step): string => sprintf('%d at %s', is_numeric($count) ? (int) $count : 0, $step))->implode(', ')));

        $reasons = DocumentEvent::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('type', [DocumentEventType::TransmissionFailed, DocumentEventType::XmlFailed])
            ->where('occurred_at', '>=', $since)
            ->when($company !== null, fn (Builder $query) => $query->whereIn('document_id', $documents()->select('id')))
            ->get(['payload'])
            ->map(fn (DocumentEvent $event): string => Redactor::text(preg_replace('/\b[A-Z]{1,4}\d+\b/', '<number>', Json::text($event->payload, 'reason') ?? 'no reason') ?? '', 160))
            ->countBy()
            ->sortDesc()
            ->take(5);

        foreach ($reasons as $reason => $count) {
            $this->line(sprintf('    %3d  %s', $count, $reason));
        }

        // The deliveries to the customers.
        $pending = $documents()->where('delivery_status', DeliveryStatus::Pending)->count();
        $undelivered = $documents()->where('delivery_status', DeliveryStatus::Failed)->where('delivery_status_at', '>=', $since)->selectRaw('delivery_step, count(*) as documents')->groupBy('delivery_step')->pluck('documents', 'delivery_step');
        $this->line(sprintf('  deliveries: %d in progress; failed in the last %d hours: %s.', $pending, $hours, $undelivered->isEmpty() ? 'none' : $undelivered->map(fn (mixed $count, string $step): string => sprintf('%d at %s', is_numeric($count) ? (int) $count : 0, $step))->implode(', ')));

        // The DIAN in the last minutes.
        $calls = DianTransmission::query()->withoutGlobalScope(CompanyScope::class)
            ->where('started_at', '>=', $now->copy()->subMinutes($minutes))
            ->whereNotNull('finished_at')
            ->when($company !== null, fn (Builder $query) => $query->whereIn('document_id', $documents()->select('id')))
            ->get(['outcome', 'duration_ms', 'http_status']);
        $transient = $calls->filter(fn (DianTransmission $call): bool => $call->outcome?->isTransient() === true);
        $this->line(sprintf(
            '  the DIAN in the last %d minutes: %d calls, %d without a validation result%s%s.',
            $minutes,
            $calls->count(),
            $transient->count(),
            $calls->isEmpty() ? '' : sprintf(' (%d%%)', (int) round(100 * $transient->count() / $calls->count())),
            $calls->isEmpty() ? '' : ': '.$calls->countBy(fn (DianTransmission $call): string => $call->outcome->value ?? 'unknown')->map(fn (int $count, string $outcome): string => "{$outcome} {$count}")->implode(', '),
        ));

        return self::SUCCESS;
    }

    private function document(string $id): int
    {
        $document = Document::query()->withoutGlobalScope(CompanyScope::class)->find($id);

        if (! $document instanceof Document) {
            $this->error("No document {$id}.");

            return self::FAILURE;
        }

        $this->line(sprintf('The document %s, %s %s of the company %s:', $document->id, $document->type->value, $document->full_number, $document->company_id));
        $this->line(sprintf(
            '  electronic: %s since %s, run %d, attempt %d%s%s.',
            $document->electronic_status->value ?? 'none',
            $document->electronic_status_at?->toIso8601ZuluString() ?? '?',
            $document->electronic_run,
            $document->electronic_attempt,
            $document->electronic_next_attempt_at === null ? '' : ', the next attempt at '.$document->electronic_next_attempt_at->toIso8601ZuluString(),
            $document->electronic_failed_step === null ? '' : ", failed at {$document->electronic_failed_step}",
        ));
        $this->line(sprintf('  delivery: %s%s.', $document->delivery_status->value ?? 'none', $document->delivery_step === null ? '' : " at {$document->delivery_step->value}, run {$document->delivery_run}"));

        $events = DocumentEvent::query()->withoutGlobalScope(CompanyScope::class)->where('document_id', $document->id)->orderBy('occurred_at')->orderBy('id')->get();

        foreach ($events->slice(-12) as $event) {
            $reason = Json::text($event->payload, 'reason');
            $this->line(sprintf('    %s  %s%s', $event->occurred_at->toIso8601ZuluString(), $event->type->value, $reason === null ? '' : ': '.Redactor::text($reason, 160)));
        }

        $calls = DianTransmission::query()->withoutGlobalScope(CompanyScope::class)->where('document_id', $document->id)->orderBy('started_at')->orderBy('id')->get();
        $this->line(sprintf('  calls to the DIAN: %d.', $calls->count()));

        foreach ($calls->slice(-10) as $call) {
            $this->line(sprintf('    run %d attempt %d  %s  %s%s  %s', $call->run, $call->attempt, $call->operation, $call->outcome->value ?? 'running', $call->http_status === null ? '' : " HTTP {$call->http_status}", $call->started_at->toIso8601ZuluString()));
        }

        return self::SUCCESS;
    }
}
