<?php

namespace App\Support\Cloud;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Delivery\Models\DocumentFile;
use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookEvent;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookSubscriptions;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Support\Console\LocalOnly;
use App\Support\Json;
use App\Support\Keys\KeyStoreEntry;
use App\Support\Keys\KmsKeyStoreEntry;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Storage\DocumentStorage;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The steps of make cloud-check (scripts/cloud-check.sh), the end-to-end check of the emulated cloud, on a
 * temporary company of its own that is deleted at the end:
 *  - start: issues the invoices to a customer with an email, with a webhook subscription of the local
 *    receiver to every event, signs in over HTTP (a session) and calls the API with a key (a rate limit),
 *    waits until a few invoices are accepted and prints only the company id, so the script kills the
 *    consumers of both queues mid-process and starts them again;
 *  - finish: waits until every invoice is accepted and delivered and every webhook delivered, then checks
 *    that the events went through the topic and both queues, the files are in the bucket, the emails went
 *    out through SES, the dead-letter queues are empty, the tables hold keys, the secrets and the signing
 *    key come from Secrets Manager and KMS, and nothing happened twice; then publishes a message no
 *    handler can read and checks it ends in the dead-letter queue of the chain;
 *  - cleanup: deletes the company when the script stopped before finish.
 */
final class CloudCheckCommand extends Command
{
    protected $signature = 'cloud:check
        {action : start, finish or cleanup}
        {company? : The id start printed, for finish and cleanup}
        {--invoices=30 : Invoices to issue}
        {--until-accepted=5 : start returns once this many invoices are accepted, so the consumers die mid-process}
        {--timeout=300 : Seconds finish waits for every invoice to be accepted and delivered}';

    protected $description = 'Steps of make cloud-check: invoices through SNS, SQS, S3, SES, DynamoDB, KMS and Secrets Manager on the local AWS emulator, with the consumers killed mid-process and a message for the dead-letter queue';

    /** The origin of the web app, one of SANCTUM_STATEFUL_DOMAINS. */
    private const string WEB_ORIGIN = 'http://localhost:8601';

    /** Seconds the poison message may take to reach the dead-letter queue. */
    private const int DEAD_LETTER_TIMEOUT = 120;

    private int $failures = 0;

    public function handle(EmulatedCloud $cloud): int
    {
        if (($refusal = LocalOnly::refusal($this->getName() ?? 'This command')) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if (! $cloud->enabled()) {
            $this->error('cloud:check runs in the emulated cloud only: make cloud-check runs it in the cloud-api container.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'start' => $this->start(),
            'finish' => $this->finish($cloud),
            'cleanup' => $this->cleanup(),
            default => $this->unknownAction(),
        };
    }

    private function unknownAction(): int
    {
        $this->error('The action is start, finish or cleanup.');

        return self::FAILURE;
    }

    private function start(): int
    {
        $count = max(1, (int) $this->option('invoices'));
        $temporary = TemporaryCompany::create('Cloud Check', 'CLD');
        $companyId = $temporary->company->id;

        try {
            $customer = Contact::create([
                'organization_type' => '1',
                'identification_type' => '31',
                'identification_number' => '990009101',
                'legal_name' => 'Cloud Check Customer SAS (synthetic)',
                'tax_scheme' => '01',
                'fiscal_responsibilities' => ['R-99-PN'],
                'email' => 'cloud-check-'.Str::lower(Str::random(8)).'@example.com',
                'address' => 'Carrera 43A # 1-50',
                'municipality_code' => '05001',
            ]);

            app(WebhookSubscriptions::class)->create([
                'url' => app(LocalWebhookReceiver::class)->urlFor($companyId),
                'events' => WebhookEventTypes::all(),
                'description' => 'make cloud-check',
            ], $temporary->user);

            Cache::put($this->startedKey($companyId), microtime(true), 3600);
            $this->http($temporary);

            $template = $temporary->numbering();
            $taxId = $temporary->iva();
            $ids = [];

            for ($index = 1; $index <= $count; $index++) {
                $ids[] = app(DocumentDrafts::class)->create(DocumentType::Invoice, [
                    'contact_id' => $customer->id,
                    'number_template_id' => $template->id,
                    'lines' => [
                        ['description' => 'Cloud check product', 'quantity' => '2', 'unit_price' => '15000', 'tax_id' => $taxId],
                        ['description' => 'Cloud check service', 'quantity' => '1', 'unit_price' => (string) (700 * $index), 'tax_id' => null],
                    ],
                ], $temporary->user)->id;
            }

            foreach ($ids as $id) {
                app(IssueDocument::class)($id, $temporary->user);
            }

            $this->progress("Temporary company {$temporary->company->name} (NIT {$temporary->company->identification_number}): {$count} invoices issued; the relay publishes their events to the topic.");
            $this->waitForAccepted(min($count, max(1, (int) $this->option('until-accepted'))));
        } catch (Throwable $exception) {
            $temporary->delete();

            throw $exception;
        }

        $this->line($companyId);

        return self::SUCCESS;
    }

    /**
     * A session of the web app (the sessions table) and a request with an API key (the limits table),
     * over HTTP to the API of this container, as the owner of the company.
     */
    private function http(TemporaryCompany $temporary): void
    {
        $api = rtrim((string) config('developers.api_check_url'), '/');
        $parts = parse_url($api);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '127.0.0.1').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $password = Str::password(24);
        $temporary->user->forceFill(['password' => $password])->save();

        $cookies = new CookieJar;
        $browser = fn (): PendingRequest => Http::withOptions(['cookies' => $cookies])
            ->withHeaders(['Origin' => self::WEB_ORIGIN, 'Referer' => self::WEB_ORIGIN.'/'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);

        $browser()->get($origin.'/sanctum/csrf-cookie')->throw();
        $token = urldecode((string) $cookies->getCookieByName('XSRF-TOKEN')?->getValue());
        $browser()->withHeaders(['X-XSRF-TOKEN' => $token])->post($api.'/auth/login', ['email' => $temporary->user->email, 'password' => $password])->throw();

        [, $key] = app(ApiKeys::class)->create('Cloud check (read only)', ApiKeyLevel::ReadOnly, creator: $temporary->user);
        Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(30)->get($api.'/invoices', ['per_page' => 1])->throw();
    }

    private function waitForAccepted(int $wanted): void
    {
        $started = microtime(true);

        do {
            $accepted = Document::query()->where('status', DocumentStatus::Issued)->where('electronic_status', 'accepted')->count();

            if ($accepted >= $wanted) {
                $this->progress(sprintf('%d invoices accepted after %.1f s; the consumers can die now.', $accepted, microtime(true) - $started));

                return;
            }

            usleep(250_000);
        } while (microtime(true) - $started < 120);

        $this->progress("Fewer than {$wanted} invoices were accepted within 120 s; going on.");
    }

    private function finish(EmulatedCloud $cloud): int
    {
        $temporary = $this->temporary();

        if ($temporary === null) {
            return self::FAILURE;
        }

        try {
            $this->check($temporary, $cloud);
        } catch (Throwable $exception) {
            $this->recordFailure('the check stopped: '.$exception->getMessage());
        } finally {
            $temporary->delete();
            $this->line('Temporary company deleted.');
        }

        $this->newLine();

        if ($this->failures > 0) {
            $this->error("make cloud-check: {$this->failures} checks FAILED.");

            return self::FAILURE;
        }

        $this->info('make cloud-check passed.');

        return self::SUCCESS;
    }

    private function check(TemporaryCompany $temporary, EmulatedCloud $cloud): void
    {
        $companyId = $temporary->company->id;
        $timeout = max(1, (int) $this->option('timeout'));
        $waitStarted = microtime(true);
        $this->line("Waiting up to {$timeout} s for every invoice to be accepted and delivered and every webhook to arrive...");

        do {
            $documents = Document::query()->where('status', DocumentStatus::Issued)->orderBy('number')->get();
            $open = $documents->filter(fn (Document $document): bool => $document->electronic_status?->isFinal() !== true || $document->delivery_status === null || $document->delivery_status === DeliveryStatus::Pending)->count();
            $pendingHooks = WebhookDelivery::query()->where('status', WebhookDeliveryStatus::Pending)->count();
            $unpublished = OutboxEvent::query()->where('company_id', $companyId)->whereNull('published_at')->count();

            if ($documents->isNotEmpty() && $open === 0 && $pendingHooks === 0 && $unpublished === 0) {
                break;
            }

            usleep(500_000);
        } while (microtime(true) - $waitStarted < $timeout);

        $cached = Cache::get($this->startedKey($companyId));
        $started = is_numeric($cached) ? (float) $cached : $waitStarted;
        $elapsed = microtime(true) - $started;
        $waited = microtime(true) - $waitStarted;
        $count = $documents->count();
        $this->newLine();

        // 1. The chain: every invoice accepted once by the simulator, numbers without gaps, and delivered.
        $accepted = $documents->filter(fn (Document $document): bool => $document->electronic_status?->value === 'accepted')->count();
        $delivered = $documents->filter(fn (Document $document): bool => $document->delivery_status === DeliveryStatus::Delivered)->count();
        $this->verify($count > 0 && $accepted === $count, "{$accepted} of {$count} invoices accepted by the local DIAN simulator.");
        $this->verify($count > 0 && $delivered === $count, "{$delivered} of {$count} invoices delivered to the customer.");

        $numbers = $documents->pluck('number')->map(fn (mixed $number): int => is_numeric($number) ? (int) $number : 0)->all();
        $gaps = $numbers === [] ? [] : array_diff(range(1, max($numbers)), $numbers);
        $this->verify($numbers !== [] && $gaps === [] && count($numbers) === count(array_unique($numbers)), sprintf('Numbers 1 to %d, consecutive, without gaps or repeats.', $numbers === [] ? 0 : max($numbers)));

        $simulated = collect(app(DianSimulator::class)->documents($temporary->company->identification_number));
        $simulatedAccepted = $simulated->where('status', 'accepted');
        $repeated = (int) $simulated->sum(fn (array $record): int => max(0, (Json::int($record, 'submissions') ?? 1) - 1));
        $this->verify($simulatedAccepted->count() === $count && $simulatedAccepted->pluck('cufe')->unique()->count() === $count && $simulated->count() === $count, sprintf('The simulator holds %d distinct accepted documents of the company and nothing else (%d records; %d repeated submissions answered by rule 90).', $simulatedAccepted->pluck('cufe')->unique()->count(), $simulated->count(), $repeated));

        // 2. Events through the topic and both queues.
        $events = OutboxEvent::query()->where('company_id', $companyId)->get();
        $routes = app(OutboxSubscribers::class)->routes();
        $toChain = $events->filter(fn (OutboxEvent $event): bool => in_array($event->type, $routes[OutboxSubscribers::CHAIN], true))->count();
        $toWebhooks = $events->filter(fn (OutboxEvent $event): bool => in_array($event->type, $routes[OutboxSubscribers::WEBHOOKS], true))->count();
        $sources = $events->filter(fn (OutboxEvent $event): bool => in_array($event->type, WebhookEventTypes::SOURCES, true))->pluck('id')->all();
        $webhookEvents = WebhookEvent::query()->whereIn('source_event_id', $sources)->count();
        $this->verify($events->isNotEmpty() && $events->whereNull('published_at')->isEmpty(), sprintf('%d outbox events published to the topic of document events: %d routed to the chain queue, %d to the webhooks queue.', $events->count(), $toChain, $toWebhooks));
        $this->verify($toChain > 0 && $accepted === $count && $delivered === $count, sprintf('The chain queue fed every step: XML, signature, transmission, answer, AttachedDocument, PDF and email of %d invoices.', $count));
        $this->verify($sources !== [] && $webhookEvents === count($sources), sprintf('The webhooks queue fed every webhook: %d webhook events for %d source events.', $webhookEvents, count($sources)));

        $hooks = WebhookDelivery::query()->get();
        $succeeded = $hooks->where('status', WebhookDeliveryStatus::Succeeded)->count();
        $this->verify($hooks->isNotEmpty() && $succeeded === $hooks->count(), sprintf('%d of %d webhook deliveries succeeded at the local receiver.', $succeeded, $hooks->count()));

        foreach (OutboxSubscribers::CHANNELS as $channel) {
            $url = config("queue.connections.sqs.queues.{$channel}");
            $dlq = config("aws.dead_letter_queues.{$channel}");
            $url = is_string($url) ? $url : '';
            $dlq = is_string($dlq) ? $dlq : '';
            $depth = $cloud->depth($url);
            $dead = $cloud->depth($dlq);
            $this->verify(array_sum($depth) === 0, sprintf('The %s queue is drained (%d waiting, %d in flight, %d delayed).', $channel, $depth['visible'], $depth['in_flight'], $depth['delayed']));
            $this->verify(array_sum($dead) === 0, sprintf('The dead-letter queue of %s is empty (%d messages).', $channel, array_sum($dead)));
        }

        // 3. Files in the bucket: the signed XML and the answer of each invoice, its AttachedDocument, PDF and zip.
        $storage = app(DocumentStorage::class);
        $keys = $cloud->objectKeys($companyId.'/');
        $files = DocumentFile::query()->get();
        $intact = 0;
        $expected = 0;

        foreach ($documents as $document) {
            $expected += 2;
            $intact += (is_string($document->xml_path) && hash('sha256', $storage->get($document->xml_path)) === $document->xml_sha256) ? 1 : 0;
            $intact += (is_string($document->dian_response_path) && in_array($document->dian_response_path, $keys, true)) ? 1 : 0;
        }

        foreach ($files as $file) {
            $expected++;
            $intact += hash('sha256', $storage->get($file->path)) === $file->sha256 ? 1 : 0;
        }

        $pdfs = $files->where('kind', DocumentFileKind::Pdf);
        $firstPdf = $pdfs->first();
        $this->verify($files->count() === 3 * $count && $intact === $expected, sprintf('%d objects of the company in the bucket; %d of %d files of the invoices read back with their SHA-256 (signed XML, answer, AttachedDocument, PDF and zip).', count($keys), $intact, $expected));
        $this->verify($firstPdf instanceof DocumentFile && str_starts_with($storage->get($firstPdf->path), '%PDF-'), 'A PDF read back from the bucket is a PDF.');

        // 4. Email through SES: one per invoice, to the customer, as the delivery attempts recorded.
        $attempts = DeliveryAttempt::query()->get();
        $sent = $attempts->where('outcome', EmailOutcome::Sent);
        $abandoned = $attempts->where('outcome', EmailOutcome::Abandoned)->count();
        $recipient = $sent->first()?->recipient;
        $emails = collect($cloud->sentEmails())->filter(fn (array $email): bool => is_string($recipient) && in_array($recipient, $email['to'], true));
        $perInvoice = $documents->map(fn (Document $document): int => $emails->filter(fn (array $email): bool => str_contains($email['subject'], ';'.$document->full_number.';'))->count())->all();
        $emailed = array_sum($perInvoice);
        $this->verify($sent->count() === $count && $sent->pluck('document_id')->unique()->count() === $count && $sent->every(fn (DeliveryAttempt $attempt): bool => $attempt->mailer === config('mail.default')), sprintf('%d emails sent through the %s mailer, one per invoice.', $sent->count(), (string) config('mail.default')));
        $this->verify($perInvoice !== [] && min($perInvoice) >= 1 && $emailed <= $count + $abandoned, sprintf('SES took %d emails for the customer, every invoice at least once (%d repeated after a consumer died during the call, which delivery at least once allows).', $emailed, max(0, $emailed - $count)));

        // 5. Keys in the tables of DynamoDB, the secrets and the signing key.
        foreach (['cache' => 'dynamodb', 'sessions' => 'dynamodb-sessions', 'limits' => 'dynamodb-limits'] as $use => $store) {
            $table = config("cache.stores.{$store}.table");
            $items = is_string($table) ? $cloud->items($table) : 0;
            $this->verify($items > 0, sprintf('The %s table (%s) holds %s keys.', $use, is_string($table) ? $table : '?', $items >= 100 ? '100 or more' : (string) $items));
        }

        $secret = $cloud->secret();
        $this->verify(($secret['APP_KEY'] ?? null) === config('app.key') && ($secret['DB_PASSWORD'] ?? null) === config('database.connections.mysql.password'), 'APP_KEY and the database password of this process come from Secrets Manager.');
        $this->verify(KmsKeyStoreEntry::query()->count() === 1 && KeyStoreEntry::query()->count() === 0, 'The signing certificate of the company is in the KMS key store (envelope encryption), not in the local one.');

        // 6. A message no handler can read ends in the dead-letter queue of the chain.
        $this->deadLetter($cloud);

        $this->newLine();
        $this->line(sprintf('   Time: %.1f s from the first issue until every invoice was accepted, delivered and notified (%.1f s of it after the consumers died: the messages they held came back after the visibility timeout of their queue).', $elapsed, $waited));
    }

    private function deadLetter(EmulatedCloud $cloud): void
    {
        $marker = 'cloud-check-poison-'.Str::lower((string) Str::ulid());
        $dlq = config('aws.dead_letter_queues.chain');
        $queue = config('queue.connections.sqs.queues.chain');

        if (! is_string($dlq) || ! is_string($queue)) {
            $this->recordFailure('the chain queue or its dead-letter queue is not configured.');

            return;
        }

        $messageId = $cloud->publish('electronic_document.sign_due', '{"marker": "'.$marker.'", not an event');
        $started = microtime(true);
        $taken = [];

        do {
            sleep(2);
            $taken = $cloud->takeDeadLetters($dlq, $marker);
        } while ($taken === [] && microtime(true) - $started < self::DEAD_LETTER_TIMEOUT);

        $receives = $taken[0]['receives'] ?? 0;
        $this->verify(count($taken) === 1, sprintf('An unreadable message (SNS %s, type electronic_document.sign_due) reached the dead-letter queue of the chain after %.1f s%s.', $messageId, microtime(true) - $started, $taken === [] ? '' : " and {$receives} receives"));

        $depth = $cloud->depth($queue);
        $dead = $cloud->depth($dlq);
        $this->verify(array_sum($depth) === 0 && array_sum($dead) === 0, sprintf('After taking it, the chain queue and its dead-letter queue are empty (%d and %d messages).', array_sum($depth), array_sum($dead)));
    }

    private function cleanup(): int
    {
        $this->temporary()?->delete();

        return self::SUCCESS;
    }

    private function temporary(): ?TemporaryCompany
    {
        $id = (string) $this->argument('company');
        $temporary = $id === '' ? null : TemporaryCompany::find($id);

        if ($temporary === null) {
            $this->error("There is no temporary company {$id}.");
        }

        return $temporary;
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

    private function recordFailure(string $message): void
    {
        $this->error("   FAIL  {$message}");
        $this->failures++;
    }

    private function startedKey(string $companyId): string
    {
        return 'cloud-check:started:'.$companyId;
    }

    private function progress(string $message): void
    {
        $this->getOutput()->getErrorStyle()->writeln($message);
    }
}
