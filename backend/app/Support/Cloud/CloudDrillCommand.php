<?php

namespace App\Support\Cloud;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookEvent;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookSubscriptions;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Support\Console\LocalOnly;
use App\Support\Json;
use App\Support\Observability\Correlation;
use App\Support\Observability\LogSchema;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The steps of make cloud-drill (scripts/cloud-drill.sh) that act on the platform, in the console function of the
 * emulated cloud, on a temporary company of its own that is deleted at the end: the incident drill of a DIAN that goes
 * down (docs/runbooks/drill-report.md). The steps that read AWS (the dead-letter queue, the alarm, the chat) run in
 * infra/functions/scripts/drill.mjs, and the response runs the commands of the runbook itself.
 *  - start: a company with a customer and a webhook subscription, and the invoices of the baseline, accepted, delivered
 *    and notified once;
 *  - incident: the DIAN simulator answers HTTP 500 to every call (its failure control, for every NIT, until restore),
 *    and more invoices are issued through the edge with an API key, as an integration issues them: their transmissions
 *    retry and give up, and their messages go to the dead-letter queue of the chain;
 *  - progress: where the invoices of the incident are, as JSON, for the script to wait on;
 *  - restore: the DIAN simulator answers again (in AWS the DIAN comes back by itself);
 *  - verify: once the dead letters are moved back, every invoice is accepted and delivered exactly once, without a
 *    gap in the numbering, one webhook of each of its events arrived, and each invoice of the incident started its
 *    transmission again from its dead letter; then the company is deleted, once its last webhook went out;
 *  - cleanup: the company deleted and the simulator restored, when the script stopped before verify.
 */
final class CloudDrillCommand extends Command
{
    protected $signature = 'cloud:drill
        {action : start, incident, progress, restore, verify or cleanup}
        {company? : The id start printed, for incident, progress, verify and cleanup}
        {--invoices=3 : Invoices to issue (start and incident)}
        {--timeout=240 : Seconds start and verify wait for every invoice to be accepted and delivered}';

    protected $description = 'Steps of make cloud-drill: an incident drill of a DIAN that goes down, in the emulated cloud';

    /** The scope of the failure control of the simulator: every NIT, as when the DIAN itself goes down. */
    private const string EVERY_NIT = '*';

    private int $failures = 0;

    public function handle(EmulatedCloud $cloud, DianSimulator $simulator): int
    {
        if (($refusal = LocalOnly::refusal($this->getName() ?? 'This command')) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if (! $cloud->enabled()) {
            $this->error('cloud:drill runs in the emulated cloud only: make cloud-drill runs it in the console function.');

            return self::FAILURE;
        }

        $result = match ($this->argument('action')) {
            'start' => $this->start($simulator),
            'incident' => $this->withCompany(fn (TemporaryCompany $company): int => $this->incident($company, $simulator)),
            'progress' => $this->withCompany(fn (TemporaryCompany $company): int => $this->progress($company, $cloud)),
            'restore' => $this->restore($simulator),
            'verify' => $this->withCompany(fn (TemporaryCompany $company): int => $this->recovery($company, $cloud, $simulator)),
            'cleanup' => $this->cleanup($simulator),
            default => $this->unknown(),
        };

        if ($result === self::SUCCESS && $this->failures > 0) {
            $this->error("{$this->failures} checks FAILED.");

            return self::FAILURE;
        }

        return $result;
    }

    private function unknown(): int
    {
        $this->error('The action is start, incident, progress, restore, verify or cleanup.');

        return self::FAILURE;
    }

    private function start(DianSimulator $simulator): int
    {
        // A drill that stopped halfway may have left the DIAN down.
        $simulator->setMode(self::EVERY_NIT, ['mode' => 'normal']);
        $count = max(1, (int) $this->option('invoices'));
        $temporary = TemporaryCompany::create('Cloud Drill', 'DRL');
        $companyId = $temporary->company->id;

        try {
            $customer = Contact::create([
                'organization_type' => '1',
                'identification_type' => '31',
                'identification_number' => '990009102',
                'legal_name' => 'Cloud Drill Customer SAS (synthetic)',
                'tax_scheme' => '01',
                'fiscal_responsibilities' => ['R-99-PN'],
                'email' => 'cloud-drill-'.Str::lower(Str::random(8)).'@example.com',
                'address' => 'Calle 10 # 43D-25',
                'municipality_code' => '05001',
            ]);
            app(WebhookSubscriptions::class)->create([
                'url' => app(LocalWebhookReceiver::class)->urlFor($companyId),
                'events' => WebhookEventTypes::all(),
                'description' => 'make cloud-drill',
            ], $temporary->user);
            Cache::put($this->key($companyId, 'customer'), $customer->id, 7200);

            $ids = [];

            for ($index = 1; $index <= $count; $index++) {
                $draft = $this->draft($temporary, $customer, $index);
                app(IssueDocument::class)($draft->id, $temporary->user);
                $ids[] = $draft->id;
            }
        } catch (Throwable $exception) {
            $temporary->delete();

            throw $exception;
        }

        $this->line("company: {$companyId}");
        $this->wait($companyId, $ids);
        $documents = Document::query()->whereKey($ids)->get();
        $done = $documents->filter(fn (Document $document): bool => $document->electronic_status === ElectronicStatus::Accepted && $document->delivery_status === DeliveryStatus::Delivered)->count();
        $this->verify($done === $count, sprintf('Baseline: %d of %d invoices issued before the incident accepted by the local DIAN simulator and delivered to the customer.', $done, $count));

        return self::SUCCESS;
    }

    private function incident(TemporaryCompany $temporary, DianSimulator $simulator): int
    {
        $companyId = $temporary->company->id;
        $customer = Contact::query()->find(Cache::get($this->key($companyId, 'customer')));

        if (! $customer instanceof Contact) {
            $this->error('The customer of start is gone.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('invoices'));
        $first = Document::query()->where('status', DocumentStatus::Issued)->count() + 1;
        $drafts = array_map(fn (int $index): Document => $this->draft($temporary, $customer, $index), range($first, $first + $count - 1));
        [, $key] = app(ApiKeys::class)->create('Cloud drill', ApiKeyLevel::ReadWrite, creator: $temporary->user);
        $api = rtrim((string) config('developers.api_check_url'), '/');
        $header = LogSchema::correlationHeader();

        // The DIAN goes down: every call of every NIT answers HTTP 500 until restore.
        $simulator->setMode(self::EVERY_NIT, ['mode' => 'error_500']);
        $this->line('incident: '.Carbon::now()->toIso8601ZuluString('millisecond'));

        $issued = [];

        foreach ($drafts as $draft) {
            $answer = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(30)->post("{$api}/invoices/{$draft->id}/issue");
            $correlation = (string) $answer->header($header);
            $this->verify($answer->successful() && Correlation::valid($correlation), sprintf('Invoice %d issued through the edge with an API key while the DIAN is down (HTTP %d): the client got %s %s.', $draft->refresh()->number ?? 0, $answer->status(), $header, $correlation === '' ? 'none' : $correlation));
            $issued[$draft->id] = $correlation;
            $this->line("correlation: {$correlation}");
        }

        Cache::put($this->key($companyId, 'incident'), $issued, 7200);

        return self::SUCCESS;
    }

    /** Where the invoices of the incident are, as one line of JSON. */
    private function progress(TemporaryCompany $temporary, EmulatedCloud $cloud): int
    {
        $ids = array_keys($this->incidentInvoices($temporary));
        $documents = Document::query()->whereKey($ids)->get();
        $calls = DianTransmission::query()->whereIn('document_id', $ids)->whereNotNull('finished_at')->get(['outcome', 'started_at']);
        $failed = $calls->filter(fn (DianTransmission $call): bool => $call->outcome?->isTransient() === true);
        $firstFailure = $failed->sortBy('started_at')->first();
        $dlq = config('aws.dead_letter_queues.chain');

        $this->line((string) json_encode([
            'invoices' => count($ids),
            'calls' => $calls->count(),
            'failed_calls' => $failed->count(),
            'first_failure_at' => $firstFailure?->started_at->toIso8601ZuluString('millisecond'),
            'waiting_for_a_retry' => $documents->filter(fn (Document $document): bool => $document->electronic_status === ElectronicStatus::Sending && $document->electronic_next_attempt_at !== null)->count(),
            'gave_up' => $documents->filter(fn (Document $document): bool => $document->electronic_status === ElectronicStatus::Failed && $document->electronic_failed_step === 'transmit')->count(),
            'accepted' => $documents->filter(fn (Document $document): bool => $document->electronic_status === ElectronicStatus::Accepted)->count(),
            'delivered' => $documents->filter(fn (Document $document): bool => $document->delivery_status === DeliveryStatus::Delivered)->count(),
            'dead_letters' => is_string($dlq) ? array_sum($cloud->depth($dlq)) : null,
        ], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function restore(DianSimulator $simulator): int
    {
        $simulator->setMode(self::EVERY_NIT, ['mode' => 'normal']);
        $this->line('The DIAN simulator answers every NIT again (mode normal).');

        return self::SUCCESS;
    }

    private function recovery(TemporaryCompany $temporary, EmulatedCloud $cloud, DianSimulator $simulator): int
    {
        try {
            $this->check($temporary, $cloud, $simulator);
        } catch (Throwable $exception) {
            $this->failures++;
            $this->error('   FAIL  The verification stopped: '.$exception->getMessage());
        } finally {
            $simulator->setMode(self::EVERY_NIT, ['mode' => 'normal']);
            $temporary->delete();
            $this->line('Temporary company deleted.');
        }

        return self::SUCCESS;
    }

    private function check(TemporaryCompany $temporary, EmulatedCloud $cloud, DianSimulator $simulator): void
    {
        $incident = $this->incidentInvoices($temporary);
        $this->wait($temporary->company->id, null);
        $documents = Document::query()->where('status', DocumentStatus::Issued)->orderBy('number')->get();
        $affected = $documents->whereIn('id', array_keys($incident));
        $count = $documents->count();

        $accepted = $affected->filter(fn (Document $document): bool => $document->electronic_status === ElectronicStatus::Accepted)->count();
        $delivered = $affected->filter(fn (Document $document): bool => $document->delivery_status === DeliveryStatus::Delivered)->count();
        $this->verify(count($incident) > 0 && $accepted === count($incident) && $delivered === count($incident), sprintf('%d of the %d invoices of the incident accepted by the local DIAN simulator and %d delivered, once their dead letters were moved back.', $accepted, count($incident), $delivered));

        // Each one came back from its dead letter: the transmission of run 1 gave up, run 2 started from the redrive.
        $resumed = $affected->filter(fn (Document $document): bool => $document->electronic_run === 2)->count();
        $gaveUp = DianTransmission::query()->whereIn('document_id', array_keys($incident))->where('run', 1)->get()->groupBy('document_id')->filter(fn ($calls): bool => $calls->every(fn (DianTransmission $call): bool => $call->outcome?->isTransient() === true))->count();
        $this->verify($resumed === count($incident) && $gaveUp === count($incident), sprintf('Each invoice of the incident gave up its transmission in run 1 (%d, every call without an answer) and started again in run 2 from its dead letter (%d), as Retry now does.', $gaveUp, $resumed));

        // Exactly once: one email, one accepted record in the simulator, one acceptance per invoice.
        $sent = DeliveryAttempt::query()->where('outcome', EmailOutcome::Sent)->get()->groupBy('document_id');
        $recipient = $sent->first()?->first()?->recipient;
        $emails = collect($cloud->sentEmails())->filter(fn (array $email): bool => is_string($recipient) && in_array($recipient, $email['to'], true));
        $perInvoice = $documents->map(fn (Document $document): int => $emails->filter(fn (array $email): bool => str_contains($email['subject'], ';'.$document->full_number.';'))->count())->all();
        $this->verify(
            $perInvoice !== [] && array_values(array_unique($perInvoice)) === [1] && $sent->count() === $count && $sent->every(fn ($attempts): bool => $attempts->count() === 1),
            sprintf('Delivered exactly once: each of the %d invoices has one email in SES and one delivery attempt sent (emails per invoice: %s).', $count, implode(' ', $perInvoice)),
        );

        $records = collect($simulator->documents($temporary->company->identification_number));
        $distinct = $records->where('status', 'accepted')->pluck('cufe')->unique()->count();
        $repeated = (int) $records->sum(fn (array $record): int => max(0, (Json::int($record, 'submissions') ?? 1) - 1));
        $this->verify($records->count() === $count && $distinct === $count, sprintf('The simulator holds %d distinct accepted documents of the company and nothing else (%d records, %d repeated submissions answered by rule 90): nothing was accepted twice.', $distinct, $records->count(), $repeated));

        $numbers = $documents->pluck('number')->map(fn (mixed $number): int => is_numeric($number) ? (int) $number : 0)->all();
        $gaps = $numbers === [] ? [] : array_diff(range(1, max($numbers)), $numbers);
        $this->verify($numbers !== [] && $gaps === [] && count($numbers) === count(array_unique($numbers)), sprintf('Numbers 1 to %d, consecutive, without gaps or repeats, the incident included.', $numbers === [] ? 0 : max($numbers)));

        // Webhooks exactly once: one of each event of each invoice, the failure of those of the incident included, each
        // delivered once to the one subscription of the company.
        $owed = $this->owedWebhooks($temporary->company->id);
        $events = WebhookEvent::query()->whereIn('source_event_id', $owed->keys()->all())->get();
        $hooks = WebhookDelivery::query()->get();
        $types = [];

        foreach ($events as $event) {
            $documentId = $owed->get($event->source_event_id)?->aggregate_id;
            $types[(string) $documentId][] = $event->type;
        }

        $wrong = $documents->filter(function (Document $document) use ($types, $incident): bool {
            $expected = ['invoice.accepted', 'invoice.delivered', ...(isset($incident[$document->id]) ? ['invoice.failed'] : []), 'invoice.issued'];
            $got = $types[$document->id] ?? [];
            sort($got);

            return $got !== $expected;
        })->count();
        $succeeded = $hooks->where('status', WebhookDeliveryStatus::Succeeded)->count();
        $this->verify(
            $events->isNotEmpty() && $events->count() === $owed->count() && $wrong === 0 && $hooks->count() === $events->count() && $succeeded === $hooks->count(),
            sprintf('Webhooks exactly once: each of the %d invoices got one webhook of invoice.issued, invoice.accepted and invoice.delivered, and each of the %d of the incident one of invoice.failed too (%d webhooks, %d deliveries, %d succeeded at the local receiver%s).', $count, count($incident), $events->count(), $hooks->count(), $succeeded, $wrong === 0 ? '' : "; {$wrong} invoices without exactly those"),
        );

        $unpublished = OutboxEvent::query()->where('company_id', $temporary->company->id)->whereNull('published_at')->count();
        $this->verify($unpublished === 0, "No outbox event of the company is left unpublished ({$unpublished}).");

        foreach (['chain', 'webhooks'] as $channel) {
            $dlq = config("aws.dead_letter_queues.{$channel}");
            $dead = is_string($dlq) ? array_sum($cloud->depth($dlq)) : -1;
            $this->verify($dead === 0, sprintf('The dead-letter queue of %s is empty (%d messages).', $channel, $dead));
        }

        $last = DeliveryAttempt::query()->whereIn('document_id', array_keys($incident))->where('outcome', EmailOutcome::Sent)->max('finished_at');
        $this->line('recovered: '.(is_string($last) ? Carbon::parse($last)->toIso8601ZuluString('millisecond') : ''));
    }

    private function cleanup(DianSimulator $simulator): int
    {
        $simulator->setMode(self::EVERY_NIT, ['mode' => 'normal']);
        $id = (string) $this->argument('company');
        $temporary = $id === '' ? null : TemporaryCompany::find($id);
        $temporary?->delete();
        $this->line($temporary === null ? 'The DIAN simulator answers again; no company to delete.' : 'The DIAN simulator answers again; temporary company deleted.');

        return self::SUCCESS;
    }

    /** A draft invoice of the drill, with its own amount. */
    private function draft(TemporaryCompany $temporary, Contact $customer, int $index): Document
    {
        return app(DocumentDrafts::class)->create(DocumentType::Invoice, [
            'contact_id' => $customer->id,
            'number_template_id' => $temporary->numbering()->id,
            'lines' => [
                ['description' => 'Cloud drill product', 'quantity' => '1', 'unit_price' => '25000', 'tax_id' => $temporary->iva()],
                ['description' => 'Cloud drill service', 'quantity' => '1', 'unit_price' => (string) (900 * $index), 'tax_id' => null],
            ],
        ], $temporary->user);
    }

    /**
     * The invoices of the incident with the correlation id each client got.
     *
     * @return array<string, string>
     */
    private function incidentInvoices(TemporaryCompany $temporary): array
    {
        $issued = Cache::get($this->key($temporary->company->id, 'incident'));

        $invoices = [];

        foreach (is_array($issued) ? $issued : [] as $id => $correlation) {
            if (is_string($id) && is_string($correlation)) {
                $invoices[$id] = $correlation;
            }
        }

        return $invoices;
    }

    /**
     * Waits until the invoices (all of the company when null) are accepted or rejected and delivered, and every webhook
     * they are owed is published and delivered: the last one, invoice.delivered, follows the email, so the company is
     * deleted only once it went out.
     *
     * @param  list<string>|null  $ids
     */
    private function wait(string $companyId, ?array $ids): void
    {
        $timeout = max(1, (int) $this->option('timeout'));
        $started = microtime(true);

        do {
            $query = Document::query()->where('status', DocumentStatus::Issued);
            $documents = ($ids === null ? $query : $query->whereKey($ids))->get();
            $open = $documents->filter(fn (Document $document): bool => $document->electronic_status !== ElectronicStatus::Accepted || $document->delivery_status !== DeliveryStatus::Delivered)->count();
            $pendingHooks = WebhookDelivery::query()->where('status', WebhookDeliveryStatus::Pending)->count();
            $owed = $this->owedWebhooks($companyId);
            $unpublished = $owed->count() - WebhookEvent::query()->whereIn('source_event_id', $owed->keys()->all())->count();

            if ($documents->isNotEmpty() && $open === 0 && $pendingHooks === 0 && $unpublished === 0) {
                $this->line(sprintf('   Every invoice accepted and delivered, and its webhooks with it, after %.1f s.', microtime(true) - $started));

                return;
            }

            usleep(500_000);
        } while (microtime(true) - $started < $timeout);

        $this->line(sprintf('   Still open after %d s: %d invoices, %d webhooks pending and %d not published yet.', $timeout, $open, $pendingHooks, $unpublished));
    }

    /**
     * The outbox events of the documents of the company that a webhook type names, by their id: the webhooks its
     * subscription, to every type, is owed, one each.
     *
     * @return Collection<string, OutboxEvent>
     */
    private function owedWebhooks(string $companyId): Collection
    {
        return OutboxEvent::query()
            ->where('company_id', $companyId)
            ->where('aggregate_type', 'document')
            ->get()
            ->filter(fn (OutboxEvent $event): bool => WebhookEventTypes::publicType($event->type, DocumentType::Invoice) !== null)
            ->keyBy(fn (OutboxEvent $event): string => $event->id);
    }

    /**
     * @param  callable(TemporaryCompany): int  $action
     */
    private function withCompany(callable $action): int
    {
        $id = (string) $this->argument('company');
        $temporary = $id === '' ? null : TemporaryCompany::find($id);

        if ($temporary === null) {
            $this->error("There is no temporary company {$id}.");

            return self::FAILURE;
        }

        return $action($temporary);
    }

    private function verify(bool $passed, string $message): void
    {
        if ($passed) {
            $this->info("   PASS  {$message}");

            return;
        }

        $this->error("   FAIL  {$message}");
        $this->failures++;
    }

    private function key(string $companyId, string $what): string
    {
        return "cloud-drill:{$what}:{$companyId}";
    }
}
