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
use App\Modules\Developers\Http\Middleware\HandleIdempotency;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookEvent;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookSubscriptions;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Support\Console\LocalOnly;
use App\Support\Database\ApplicationIdentityCommand;
use App\Support\Database\RefreshingMySqlConnector;
use App\Support\Health\SchedulerHeartbeat;
use App\Support\Json;
use App\Support\Keys\KeyStoreEntry;
use App\Support\Keys\KmsKeyStoreEntry;
use App\Support\Observability\Correlation;
use App\Support\Observability\LogSchema;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\Sqs\SqsEventsQueue;
use App\Support\Storage\DocumentStorage;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The steps of make cloud-check (scripts/cloud-check.sh), the end-to-end check of the emulated cloud,
 * where every step runs in the console function and every invoice goes through the functions, on a
 * temporary company of its own that is deleted at the end:
 *  - start: issues the invoices to a customer with an email, with a webhook subscription of the local
 *    receiver to every event, and prints the company id;
 *  - fast: waits until those invoices are accepted and delivered, reports the time from issue to delivery
 *    (median and 95th percentile), checks that the writers published every outbox event themselves (the
 *    fast path, without waiting for the relay function), and, through the edge on the origin of the web
 *    app, signs in with the session cookie and the CSRF token, downloads the XML, the PDF and the zip of an
 *    invoice and checks their SHA-256, calls the API with a key (a 403 stays a problem of the API), and
 *    checks that an address the client writes does not change the one the rate limits count;
 *  - traced: through the edge, with an API key, as an integration would, issues one invoice and prints the
 *    correlation id the answer carried and the document, once the invoice is accepted, delivered and notified,
 *    for make cloud-check to follow it in the logs of the functions with the saved query; an id the client
 *    sends is kept when it has the form of one and replaced otherwise;
 *  - partial: sends the chain queue, in one batch, a message no handler can read with a few valid ones:
 *    the valid ones run once and the unreadable one ends in the dead-letter queue (on Floci 2.5.0, which
 *    holds a failed record for the visibility timeout of its queue, it is received again and purged);
 *  - chaos: issues more invoices, leaves one outbox event unpublished as if its writer died right after
 *    its commit, and returns once a few are accepted, so the script kills the containers of the functions;
 *  - finish: waits until everything is accepted, delivered and notified, then checks the topic, both
 *    queues, the bucket, SES, the tables, KMS and Secrets Manager, that nothing happened twice, that the
 *    relay function published the event left behind within two minutes, and that the scheduler ran;
 *  - identities: the console function connects as the migrations identity of the database, over TLS, and
 *    holds its schema; the application identity, which every other function connects as, reads and writes
 *    data, is refused every change of the schema, and is refused without TLS;
 *  - rotation: on a company of its own, with the functions warm, rotates the application identity twice the
 *    way the rotation function of AWS does with alternating users (the emulator cannot run it): the first
 *    rotation moves the secret to the other user, and the warm functions keep the old one, still valid; the
 *    second sets a new password on the user they hold, so they must read their secret again. Requests through
 *    API Gateway and invoices through the chain and the webhooks must work, before and after each, without
 *    an error anyone would see;
 *  - cleanup: deletes the company when the script stopped before finish;
 *  - demo: what make cloud-e2e needs before its browser opens (it changes nothing): the demo data of make
 *    cloud-demo, and a local DIAN simulator that holds no document of the demo companies under a number this
 *    database has not issued yet. The simulator is shared with the local stack, and an invoice the local stack
 *    issued there since (make e2e, make docs-examples) can hold the number the next invoice of this database takes,
 *    which the simulator would reject with rule 90. The step fails at once and says what to run.
 */
final class CloudCheckCommand extends Command
{
    protected $signature = 'cloud:check
        {action : start, fast, traced, partial, chaos, finish, identities, rotation, cleanup or demo}
        {company? : The id start printed, for fast, traced, chaos, finish and cleanup}
        {--invoices=30 : Invoices to issue (start and chaos)}
        {--until-accepted=3 : chaos returns once this many of its invoices are accepted, so the functions die mid-process}
        {--timeout=300 : Seconds fast and finish wait for every invoice to be accepted and delivered}';

    protected $description = 'Steps of make cloud-check: invoices through the functions, API Gateway, SNS, SQS, S3, SES, DynamoDB, KMS and Secrets Manager on the local AWS emulator';

    /** The origin of the web app, one of SANCTUM_STATEFUL_DOMAINS. */
    private const string WEB_ORIGIN = 'http://localhost:8601';

    /**
     * Seconds the messages of the partial batch may take: the unreadable one is received maxReceiveCount times
     * (twice where the emulator holds a failed record for the visibility timeout of its queue).
     */
    private const int PARTIAL_TIMEOUT = 150;

    /** The relay function must publish an event left behind within this many seconds. */
    private const int SAFETY_NET_SECONDS = 120;

    /** Valid messages sent with the unreadable one. */
    private const int PROBES = 4;

    /** What the body of every unreadable message of the partial step starts with. */
    private const string POISON = 'cloud-check-poison-';

    /**
     * Seconds finish waits for the queues to drain: a message a killed function held comes back after the
     * visibility timeout of its queue (60 s), possibly once every invoice is done, and runs again as a no-op.
     */
    private const int DRAIN_SECONDS = 90;

    private int $failures = 0;

    public function handle(EmulatedCloud $cloud): int
    {
        if (($refusal = LocalOnly::refusal($this->getName() ?? 'This command')) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if (! $cloud->enabled()) {
            $this->error('cloud:check runs in the emulated cloud only: make cloud-check runs it in the console function.');

            return self::FAILURE;
        }

        $result = match ($this->argument('action')) {
            'start' => $this->start($cloud),
            'fast' => $this->withCompany(fn (TemporaryCompany $company) => $this->fast($company)),
            'traced' => $this->withCompany(fn (TemporaryCompany $company) => $this->traced($company)),
            'partial' => $this->partial($cloud),
            'chaos' => $this->withCompany(fn (TemporaryCompany $company) => $this->chaos($company)),
            'finish' => $this->finish($cloud),
            'identities' => $this->identities($cloud),
            'rotation' => $this->rotation($cloud),
            'cleanup' => $this->cleanup(),
            'demo' => $this->demo(app(DianSimulator::class)),
            default => $this->unknownAction(),
        };

        if ($result === self::SUCCESS && $this->failures > 0) {
            $this->error("{$this->failures} checks FAILED.");

            return self::FAILURE;
        }

        return $result;
    }

    private function unknownAction(): int
    {
        $this->error('The action is start, fast, traced, partial, chaos, finish, identities, rotation, cleanup or demo.');

        return self::FAILURE;
    }

    private function start(EmulatedCloud $cloud): int
    {
        // The unreadable message of a partial step that was interrupted ends in the dead-letter queue of the
        // chain, which finish expects empty.
        $dlq = config('aws.dead_letter_queues.chain');
        $leftovers = is_string($dlq) ? count($cloud->takeDeadLetters($dlq, self::POISON)) : 0;

        if ($leftovers > 0) {
            $this->line("Took {$leftovers} unreadable messages an earlier make cloud-check left in the dead-letter queue of the chain.");
        }

        $count = max(1, (int) $this->option('invoices'));
        $temporary = TemporaryCompany::create('Cloud Check', 'CLD');
        $companyId = $temporary->company->id;

        try {
            $customer = $this->customerWithWebhooks($temporary);

            Cache::put($this->key($companyId, 'customer'), $customer->id, 7200);
            Cache::put($this->key($companyId, 'started'), microtime(true), 7200);
            $issued = $this->issue($temporary, $customer, $count, 1);
            Cache::put($this->key($companyId, 'fast'), $issued, 7200);

            $this->line("{$count} invoices issued by Cloud Check (NIT {$temporary->company->identification_number}); the writers publish their events to the topic as they commit.");
        } catch (Throwable $exception) {
            $temporary->delete();

            throw $exception;
        }

        $this->line("company: {$companyId}");

        return self::SUCCESS;
    }

    /**
     * Issues invoices and returns when each one was issued, by document id.
     *
     * @return array<string, float>
     */
    private function issue(TemporaryCompany $temporary, Contact $customer, int $count, int $first): array
    {
        $template = $temporary->numbering();
        $taxId = $temporary->iva();
        $ids = [];

        for ($index = $first; $index < $first + $count; $index++) {
            $ids[] = app(DocumentDrafts::class)->create(DocumentType::Invoice, [
                'contact_id' => $customer->id,
                'number_template_id' => $template->id,
                'lines' => [
                    ['description' => 'Cloud check product', 'quantity' => '2', 'unit_price' => '15000', 'tax_id' => $taxId],
                    ['description' => 'Cloud check service', 'quantity' => '1', 'unit_price' => (string) (700 * $index), 'tax_id' => null],
                ],
            ], $temporary->user)->id;
        }

        $issued = [];

        foreach ($ids as $id) {
            app(IssueDocument::class)($id, $temporary->user);
            $issued[$id] = microtime(true);
        }

        return $issued;
    }

    private function fast(TemporaryCompany $temporary): int
    {
        $companyId = $temporary->company->id;
        $issued = Cache::get($this->key($companyId, 'fast'));
        $issued = is_array($issued) ? array_filter($issued, 'is_float') : [];

        $this->waitForChain($companyId, array_keys($issued));
        $documents = Document::query()->whereKey(array_keys($issued))->get();
        $count = count($issued);

        $accepted = $documents->filter(fn (Document $document): bool => $document->electronic_status?->value === 'accepted')->count();
        $delivered = $documents->filter(fn (Document $document): bool => $document->delivery_status === DeliveryStatus::Delivered)->count();
        $this->verify($count > 0 && $accepted === $count && $delivered === $count, "{$accepted} of {$count} invoices accepted by the local DIAN simulator and {$delivered} delivered to the customer.");

        // From the issue until SES took the email of the invoice.
        $sent = DeliveryAttempt::query()->whereIn('document_id', array_keys($issued))->where('outcome', EmailOutcome::Sent)->get()->keyBy('document_id');
        $latencies = [];

        foreach ($issued as $id => $at) {
            $attempt = $sent->get($id);

            if ($attempt instanceof DeliveryAttempt && $attempt->finished_at !== null) {
                $latencies[] = $attempt->finished_at->getTimestampMs() / 1000 - $at;
            }
        }

        sort($latencies);
        $this->verify(count($latencies) === $count, sprintf('Time from issue to delivery of the %d invoices: median %.1f s, 95th percentile %.1f s, slowest %.1f s.', count($latencies), $this->percentile($latencies, 50), $this->percentile($latencies, 95), $latencies === [] ? 0 : max($latencies)));

        // Every event went out with the commit of its writer: the relay function takes only older ones.
        $after = max(1, config()->integer('outbox.pending_after_seconds'));
        $events = OutboxEvent::query()->where('company_id', $companyId)->get();
        $slow = $events->filter(fn (OutboxEvent $event): bool => $event->published_at === null || $event->published_at->getTimestamp() - $event->created_at->getTimestamp() >= $after);
        $this->verify($events->isNotEmpty() && $slow->isEmpty(), sprintf('The writers published all %d outbox events of these invoices as they committed (the fast path); none waited for the relay function, which takes events older than %d s (%d did).', $events->count(), $after, $slow->count()));

        $this->http($temporary, $documents);

        return self::SUCCESS;
    }

    /**
     * One invoice issued through the edge with an API key, whose journey make cloud-check follows in the logs: the
     * correlation id of the answer, once the invoice is accepted, delivered and its webhooks arrived. The id a
     * client sends is kept only when it has the form of one.
     */
    private function traced(TemporaryCompany $temporary): int
    {
        $api = rtrim((string) config('developers.api_check_url'), '/');
        $companyId = $temporary->company->id;
        $customer = Contact::query()->find(Cache::get($this->key($companyId, 'customer')));

        if (! $customer instanceof Contact) {
            $this->error('The customer of start is gone.');

            return self::FAILURE;
        }

        $header = LogSchema::correlationHeader();
        $chosen = Correlation::generate();
        $kept = Http::withHeaders([$header => $chosen])->acceptJson()->connectTimeout(5)->timeout(30)->get($api.'/health');
        $replaced = Http::withHeaders([$header => 'not-a-correlation-id; a client wrote it'])->acceptJson()->connectTimeout(5)->timeout(30)->get($api.'/health');
        $this->verify(
            $kept->header($header) === $chosen && Correlation::valid($replaced->header($header)) && $replaced->header($header) !== $chosen,
            sprintf('Through the edge the API answers %s on every request: the id a client sends is kept when it has the form of one (%s), replaced otherwise (%s).', $header, $kept->header($header) === $chosen ? 'kept' : 'NOT kept', Correlation::valid($replaced->header($header)) ? 'replaced' : 'NOT replaced'),
        );

        $draft = app(DocumentDrafts::class)->create(DocumentType::Invoice, [
            'contact_id' => $customer->id,
            'number_template_id' => $temporary->numbering()->id,
            'lines' => [['description' => 'Cloud check traced service', 'quantity' => '1', 'unit_price' => '42000', 'tax_id' => $temporary->iva()]],
        ], $temporary->user);
        [, $key] = app(ApiKeys::class)->create('Cloud check (traced)', ApiKeyLevel::ReadWrite, creator: $temporary->user);
        $issued = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(30)->post("{$api}/invoices/{$draft->id}/issue");
        $correlation = $issued->header($header);
        $this->verify(
            $issued->successful() && $this->throughApiGateway($issued) && Correlation::valid($correlation),
            sprintf('An invoice issued through the edge with an API key (HTTP %d) answers the correlation id of its journey in %s: %s.', $issued->status(), $header, $correlation === '' ? 'none' : $correlation),
        );

        $this->waitForChain($companyId, [$draft->id]);
        $document = $draft->refresh();
        $this->verify($document->electronic_status?->value === 'accepted' && $document->delivery_status === DeliveryStatus::Delivered, sprintf('The traced invoice %s was accepted and delivered.', $document->full_number));

        $this->line("correlation: {$correlation}");
        $this->line("document: {$document->id}");

        return self::SUCCESS;
    }

    /**
     * Through the edge, as the web app does on its own origin: the CSRF cookie, the sign-in with the session cookie
     * and the token, the current user, and the downloads of an invoice; then a request with an API key (the limits
     * table), a 403 of the API that stays a problem of the API, and a sign-in whose client writes an address of its
     * own, which the rate limit of the sign-in does not count by: it counts the address the edge saw (the proxy in
     * front of the distribution, here).
     *
     * @param  Collection<int, Document>  $documents
     */
    private function http(TemporaryCompany $temporary, Collection $documents): void
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

        $csrf = $browser()->get($origin.'/sanctum/csrf-cookie');
        $token = urldecode((string) $cookies->getCookieByName('XSRF-TOKEN')?->getValue());
        $refused = $browser()->post($api.'/auth/login', ['email' => $temporary->user->email, 'password' => $password]);
        $login = $browser()->withHeaders(['X-XSRF-TOKEN' => $token])->post($api.'/auth/login', ['email' => $temporary->user->email, 'password' => $password]);
        $me = $browser()->withHeaders(['X-XSRF-TOKEN' => $token])->get($api.'/auth/me');
        $this->verify(
            $csrf->successful() && $token !== '' && $refused->status() === 419 && $login->successful() && $me->successful() && $me->json('data.email', $me->json('email')) === $temporary->user->email && $cookies->getCookieByName(config()->string('session.cookie')) !== null && $this->throughApiGateway($login),
            sprintf('Through the edge (%s, the origin %s of the web app), the session cookie and the CSRF token sign in: the CSRF cookie answered %d, a sign-in without the token %d, with it %d, and the current user %d.', $origin, self::WEB_ORIGIN, $csrf->status(), $refused->status(), $login->status(), $me->status()),
        );

        $document = $documents->first();

        if ($document instanceof Document) {
            $files = DocumentFile::query()->where('document_id', $document->id)->get()->keyBy(fn (DocumentFile $file): string => $file->kind->value);
            $downloads = [];

            foreach (['xml' => $document->xml_sha256, 'pdf' => $files->get(DocumentFileKind::Pdf->value)?->sha256, 'package' => $files->get(DocumentFileKind::Package->value)?->sha256] as $file => $expected) {
                $response = $browser()->withHeaders(['X-Company-Id' => $temporary->company->id, 'Accept' => '*/*'])->get("{$api}/invoices/{$document->id}/{$file}");
                $downloads[$file] = $response->successful() && is_string($expected) && hash('sha256', $response->body()) === $expected && $this->throughApiGateway($response);
                $this->line(sprintf('   %s: HTTP %d, %s, %d bytes, SHA-256 %s.', $file, $response->status(), $response->header('Content-Type'), strlen($response->body()), $downloads[$file] ? 'as stored' : 'DIFFERENT'));
            }

            $this->verify(! in_array(false, $downloads, true) && str_starts_with($browser()->withHeaders(['X-Company-Id' => $temporary->company->id, 'Accept' => '*/*'])->get("{$api}/invoices/{$document->id}/pdf")->body(), '%PDF-'), "The XML, the PDF and the zip of invoice {$document->full_number}, downloaded through the edge with the session, have the SHA-256 the database keeps.");
        }

        $reference = Http::connectTimeout(5)->timeout(30)->get($origin.'/docs/api');
        $this->verify($reference->successful() && str_contains($reference->body(), '"openapi"') && str_contains($reference->body(), 'integrity="sha384-') && str_contains((string) $reference->header('Content-Security-Policy'), "'sha256-") && $this->throughApiGateway($reference), sprintf('The API reference answers at /docs/api through the edge, its renderer pinned by Subresource Integrity and its inline scripts by hash (HTTP %d, %d bytes).', $reference->status(), strlen($reference->body())));

        [, $key] = app(ApiKeys::class)->create('Cloud check (read only)', ApiKeyLevel::ReadOnly, creator: $temporary->user);
        $listing = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(30)->get($api.'/invoices', ['per_page' => 1]);
        $next = $listing->json('links.first');
        $next = is_string($next) ? $next : '';
        $this->verify($listing->successful() && $this->throughApiGateway($listing) && str_starts_with($next, rtrim(config()->string('app.url'), '/').'/api/v1/invoices'), sprintf('An API key lists the invoices through the edge (HTTP %d), and the links of its pages name the public origin, not API Gateway (%s).', $listing->status(), $next));

        // A 403 of the API is a problem of the API, not the web app: a key on a route of the web app session.
        $forbidden = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(30)->get($api.'/api-keys');
        $this->verify($forbidden->status() === 403 && str_contains((string) $forbidden->header('Content-Type'), 'application/problem+json') && $forbidden->json('status') === 403, sprintf('Through the edge a 403 of the API stays a problem of the API: %d %s.', $forbidden->status(), $forbidden->header('Content-Type')));

        // The client writes addresses of its own, in X-Forwarded-For, in CloudFront-Viewer-Address and in both, and
        // ends X-Forwarded-For with the peer API Gateway reports (127.0.0.1 in the emulator), the entry the application
        // drops; the sign-in counts the address the edge saw, the entry it appended to X-Forwarded-For. Its limit
        // counts every attempt before anything else, these included, which come without the session of the web app (400).
        $seen = (string) gethostbyname((string) parse_url($api, PHP_URL_HOST));
        $lasts = range(20, 250);
        shuffle($lasts);
        $spoofed = array_map(fn (int $last): string => '203.0.113.'.$last, array_slice($lasts, 0, 5));
        $attempts = [
            'X-Forwarded-For' => ['X-Forwarded-For' => $spoofed[0]],
            'CloudFront-Viewer-Address' => ['CloudFront-Viewer-Address' => "{$spoofed[1]}:4321"],
            'both, two entries' => ['X-Forwarded-For' => "{$spoofed[2]}, {$spoofed[3]}", 'CloudFront-Viewer-Address' => "{$spoofed[2]}:4321"],
            'X-Forwarded-For ending with the peer of API Gateway' => ['X-Forwarded-For' => "{$spoofed[4]}, 127.0.0.1"],
        ];
        $before = $this->attempts('login', $seen);
        $statuses = [];

        foreach ($attempts as $headers) {
            $statuses[] = Http::withHeaders($headers)->acceptJson()->connectTimeout(5)->timeout(30)
                ->post($api.'/auth/login', ['email' => 'nobody-'.Str::lower(Str::random(8)).'@example.com', 'password' => Str::password(16)])
                ->status();
        }

        $counted = $this->attempts('login', $seen) - $before;
        $written = array_sum(array_map(fn (string $address): int => $this->attempts('login', $address), $spoofed));
        $this->verify(
            array_unique($statuses) === [400] && $counted === count($attempts) && $written === 0,
            sprintf('A client that writes its address in %s changes nothing: the rate limit of the sign-in counted the %d attempts for the address the edge saw, %s (%d), and %d for the %d addresses the client wrote (HTTP %s).', implode(', ', array_keys($attempts)), count($attempts), $seen, $counted, $written, count($spoofed), implode(', ', array_unique($statuses))),
        );

        // The session of the sign-in and the rate limits of the sign-in and of the key, in their tables.
        $this->tables(app(EmulatedCloud::class), ['sessions' => 'dynamodb-sessions', 'limits' => 'dynamodb-limits']);
    }

    /**
     * Each table of DynamoDB holds keys.
     *
     * @param  array<string, string>  $stores  Use => cache store of its table.
     */
    private function tables(EmulatedCloud $cloud, array $stores): void
    {
        foreach ($stores as $use => $store) {
            $table = config("cache.stores.{$store}.table");
            $items = is_string($table) ? $cloud->items($table) : 0;
            $this->verify($items > 0, sprintf('The %s table (%s) holds %s keys.', $use, is_string($table) ? $table : '?', $items >= 100 ? '100 or more' : (string) $items));
        }
    }

    /** The requests a named rate limit of the application counted for an address, as ThrottleRequests keys them. */
    private function attempts(string $limiter, string $address): int
    {
        $attempts = RateLimiter::attempts(md5($limiter.$limiter.'|'.$address));

        return is_numeric($attempts) ? (int) $attempts : 0;
    }

    /** API Gateway adds a request id of its own to every answer, which the edge passes on; the application sets none of these. */
    private function throughApiGateway(Response $response): bool
    {
        return $response->header('apigw-requestid') !== '' || $response->header('x-amzn-RequestId') !== '';
    }

    private function partial(EmulatedCloud $cloud): int
    {
        $marker = self::POISON.Str::lower((string) Str::ulid());
        $queue = Queue::connection('sqs');
        $url = config('queue.connections.sqs.queues.chain');
        $dlq = config('aws.dead_letter_queues.chain');

        if (! $queue instanceof SqsEventsQueue || ! is_string($url) || ! is_string($dlq)) {
            $this->recordFailure('the chain queue or its dead-letter queue is not configured.');

            return self::SUCCESS;
        }

        // An event no handler can read, in the middle of valid jobs, all in one call so they arrive together.
        $messages = [];

        for ($index = 0; $index < self::PROBES; $index++) {
            $messages[] = ['body' => $queue->bodyOf(new CloudCheckProbe($marker, $index), 'chain')];
        }

        array_splice($messages, 2, 0, [['body' => '{"marker": "'.$marker.'", not an event', 'type' => 'electronic_document.sign_due']]);
        // AWS keeps the visibility the function sets on a failed record, so the unreadable message comes back a
        // second later and reaches the dead-letter queue after its 10 receives. Floci 2.5.0 holds it for the
        // visibility timeout of the queue instead, 60 s per receive: there the step waits for its second
        // receive only (docs/limitations.md).
        $keeps = $cloud->keepsVisibilityOfFailedRecords();
        $ids = $cloud->sendMessages($url, $messages);
        $poison = $ids[2];
        $started = microtime(true);
        $taken = [];
        $held = [];

        do {
            sleep(2);
            $ran = array_filter(range(0, self::PROBES - 1), fn (int $index): bool => Cache::get(CloudCheckProbe::runsKey($marker, $index)) !== null);

            if ($keeps) {
                $taken = $taken === [] ? $cloud->takeDeadLetters($dlq, $marker) : $taken;
                $done = $taken !== [];
            } else {
                $held = $cloud->peek($url);
                $done = $this->receivesOf($held, $poison) >= 2;
            }
        } while ((! $done || count($ran) < self::PROBES) && microtime(true) - $started < self::PARTIAL_TIMEOUT);

        $runs = array_map(function (int $index) use ($marker): int {
            $runs = Cache::get(CloudCheckProbe::runsKey($marker, $index));

            return is_numeric($runs) ? (int) $runs : 0;
        }, range(0, self::PROBES - 1));
        $batches = array_map(function (int $index) use ($marker): array {
            $batch = Cache::get(CloudCheckProbe::batchKey($marker, $index));

            return is_array($batch) ? $batch : [];
        }, range(0, self::PROBES - 1));
        $together = array_filter($batches, fn (array $batch): bool => in_array($poison, $batch, true));

        $this->verify($runs === array_fill(0, self::PROBES, 1), sprintf('The %d valid messages sent with an unreadable one ran once each (%s).', self::PROBES, implode(', ', $runs)));
        $this->verify($together !== [], sprintf('%d of them ran in the same batch as the unreadable message: the chain function answered a partial batch response, so the failure did not send them back.', count($together)));

        if ($keeps) {
            $receives = $taken[0]['receives'] ?? 0;
            $this->verify(count($taken) === 1, sprintf('The unreadable message %s reached the dead-letter queue of the chain after %.1f s%s, retried on its own.', $poison, microtime(true) - $started, $taken === [] ? '' : " and {$receives} receives"));
        } else {
            $receives = $this->receivesOf($held, $poison);
            $this->verify($receives >= 2 && count($held) === 1, sprintf('The chain function reported the unreadable message %s as failed: the chain queue kept it, alone, and gave it back on its own (%d receives in %.1f s).', $poison, $receives, microtime(true) - $started));
            $this->note(sprintf('Floci %s returns a record a function reported as failed after the visibility timeout of its queue (60 s), not after the 1 s the function set, so the 10 receives that take the unreadable message to the dead-letter queue would last about 10 minutes: the step stops at its second receive and purges the chain queue (docs/limitations.md).', $cloud->version()));

            if (count($held) === 1) {
                $cloud->purge($url);
            }
        }

        $depth = $cloud->depth($url);
        $dead = $cloud->depth($dlq);
        $this->verify(array_sum($depth) === 0 && array_sum($dead) === 0, sprintf('After taking it, the chain queue and its dead-letter queue are empty (%d and %d messages).', array_sum($depth), array_sum($dead)));

        return self::SUCCESS;
    }

    private function chaos(TemporaryCompany $temporary): int
    {
        $companyId = $temporary->company->id;
        $count = max(1, (int) $this->option('invoices'));
        $customerId = Cache::get($this->key($companyId, 'customer'));
        $customer = is_string($customerId) ? Contact::query()->find($customerId) : null;

        if (! $customer instanceof Contact) {
            $this->recordFailure('start did not leave the customer of the company.');

            return self::SUCCESS;
        }

        $first = Document::query()->where('status', DocumentStatus::Issued)->count() + 1;

        // An event left in the outbox as if its writer died right after its commit: only the relay
        // function can publish it. A no-op for the chain: the document is past that step.
        $accepted = Document::query()->where('electronic_status', 'accepted')->orderBy('number')->firstOrFail();
        $fastPath = config()->boolean('outbox.publish_after_commit');
        config(['outbox.publish_after_commit' => false]);
        $orphan = app(Outbox::class)->record($companyId, 'document', $accepted->id, 'electronic_document.sign_due', ['document_id' => $accepted->id, 'run' => $accepted->electronic_run]);
        config(['outbox.publish_after_commit' => $fastPath]);
        Cache::put($this->key($companyId, 'orphan'), $orphan->id, 7200);

        $issued = $this->issue($temporary, $customer, $count, $first);
        Cache::put($this->key($companyId, 'chaos'), $issued, 7200);
        $this->line("{$count} more invoices issued, and one outbox event left unpublished as if its writer had died after its commit.");

        $wanted = min($count, max(1, (int) $this->option('until-accepted')));
        $started = microtime(true);

        do {
            $done = Document::query()->whereKey(array_keys($issued))->where('electronic_status', 'accepted')->count();

            if ($done >= $wanted) {
                break;
            }

            usleep(250_000);
        } while (microtime(true) - $started < 120);

        $this->line(sprintf('%d of them accepted after %.1f s; the functions can die now.', $done, microtime(true) - $started));

        return self::SUCCESS;
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

        return self::SUCCESS;
    }

    private function check(TemporaryCompany $temporary, EmulatedCloud $cloud): void
    {
        $companyId = $temporary->company->id;
        $waitStarted = microtime(true);
        $this->waitForChain($companyId, null);
        $documents = Document::query()->where('status', DocumentStatus::Issued)->orderBy('number')->get();
        $cached = Cache::get($this->key($companyId, 'started'));
        $elapsed = microtime(true) - (is_numeric($cached) ? (float) $cached : $waitStarted);
        $count = $documents->count();
        $this->newLine();

        // 1. The chain: every invoice accepted once by the simulator, numbers without gaps, and delivered.
        $accepted = $documents->filter(fn (Document $document): bool => $document->electronic_status?->value === 'accepted')->count();
        $delivered = $documents->filter(fn (Document $document): bool => $document->delivery_status === DeliveryStatus::Delivered)->count();
        $this->verify($count > 0 && $accepted === $count, "{$accepted} of {$count} invoices accepted by the local DIAN simulator, those killed mid-process included.");
        $this->verify($count > 0 && $delivered === $count, "{$delivered} of {$count} invoices delivered to the customer.");

        $numbers = $documents->pluck('number')->map(fn (mixed $number): int => is_numeric($number) ? (int) $number : 0)->all();
        $gaps = $numbers === [] ? [] : array_diff(range(1, max($numbers)), $numbers);
        $this->verify($numbers !== [] && $gaps === [] && count($numbers) === count(array_unique($numbers)), sprintf('Numbers 1 to %d, consecutive, without gaps or repeats.', $numbers === [] ? 0 : max($numbers)));

        $simulated = collect(app(DianSimulator::class)->documents($temporary->company->identification_number));
        $simulatedAccepted = $simulated->where('status', 'accepted');
        $repeated = (int) $simulated->sum(fn (array $record): int => max(0, (Json::int($record, 'submissions') ?? 1) - 1));
        $this->verify($simulatedAccepted->count() === $count && $simulatedAccepted->pluck('cufe')->unique()->count() === $count && $simulated->count() === $count, sprintf('The simulator holds %d distinct accepted documents of the company and nothing else (%d records; %d repeated submissions answered by rule 90): nothing was issued twice.', $simulatedAccepted->pluck('cufe')->unique()->count(), $simulated->count(), $repeated));

        // 2. Events through the topic and both queues.
        $events = OutboxEvent::query()->where('company_id', $companyId)->get();
        $routes = app(OutboxSubscribers::class)->routes();
        $toChain = $events->filter(fn (OutboxEvent $event): bool => in_array($event->type, $routes[OutboxSubscribers::CHAIN], true))->count();
        $toWebhooks = $events->filter(fn (OutboxEvent $event): bool => in_array($event->type, $routes[OutboxSubscribers::WEBHOOKS], true))->count();
        $sources = $events->filter(fn (OutboxEvent $event): bool => in_array($event->type, WebhookEventTypes::SOURCES, true))->pluck('id')->all();
        $webhookEvents = WebhookEvent::query()->whereIn('source_event_id', $sources)->count();
        $this->verify($events->isNotEmpty() && $events->whereNull('published_at')->isEmpty(), sprintf('%d outbox events published to the topic of document events: %d routed to the chain queue, %d to the webhooks queue.', $events->count(), $toChain, $toWebhooks));
        $this->verify($toChain > 0 && $accepted === $count && $delivered === $count, sprintf('The chain function took every step from its queue: XML, signature, transmission, answer, AttachedDocument, PDF and email of %d invoices.', $count));
        $this->verify($sources !== [] && $webhookEvents === count($sources), sprintf('The webhooks function took every webhook from its queue: %d webhook events for %d source events.', $webhookEvents, count($sources)));

        $hooks = WebhookDelivery::query()->get();
        $succeeded = $hooks->where('status', WebhookDeliveryStatus::Succeeded)->count();
        $this->verify($hooks->isNotEmpty() && $succeeded === $hooks->count(), sprintf('%d of %d webhook deliveries succeeded at the local receiver.', $succeeded, $hooks->count()));

        $drainedBy = microtime(true) + self::DRAIN_SECONDS;

        while (! $this->drained($cloud) && microtime(true) < $drainedBy) {
            sleep(2);
        }

        foreach (OutboxSubscribers::CHANNELS as $channel) {
            $url = config("queue.connections.sqs.queues.{$channel}");
            $dlq = config("aws.dead_letter_queues.{$channel}");
            $depth = $cloud->depth(is_string($url) ? $url : '');
            $dead = $cloud->depth(is_string($dlq) ? $dlq : '');
            $this->verify(array_sum($depth) === 0, sprintf('The %s queue is drained (%d waiting, %d in flight, %d delayed), every message a killed function held done again.', $channel, $depth['visible'], $depth['in_flight'], $depth['delayed']));
            $this->verify(array_sum($dead) === 0, sprintf('The dead-letter queue of %s is empty (%d messages).', $channel, array_sum($dead)));
        }

        // 3. The safety net: the event left behind went out through the relay function.
        $orphanId = Cache::get($this->key($companyId, 'orphan'));
        $orphan = is_string($orphanId) ? $events->firstWhere('id', $orphanId) : null;
        $after = max(1, config()->integer('outbox.pending_after_seconds'));
        $waited = $orphan instanceof OutboxEvent && $orphan->published_at !== null ? $orphan->published_at->getTimestamp() - $orphan->created_at->getTimestamp() : null;
        $this->verify($waited !== null && $waited >= $after && $waited < self::SAFETY_NET_SECONDS, sprintf('The outbox event left unpublished after its commit was published by the relay function %s s after it was recorded (it takes events older than %d s, once a minute).', $waited ?? 'never', $after));

        // 4. Files in the bucket: the signed XML and the answer of each invoice, its AttachedDocument, PDF and zip.
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

        $firstPdf = $files->where('kind', DocumentFileKind::Pdf)->first();
        $this->verify($files->count() === 3 * $count && $intact === $expected, sprintf('%d objects of the company in the bucket; %d of %d files of the invoices read back with their SHA-256 (signed XML, answer, AttachedDocument, PDF and zip).', count($keys), $intact, $expected));
        $this->verify($firstPdf instanceof DocumentFile && str_starts_with($storage->get($firstPdf->path), '%PDF-'), 'A PDF read back from the bucket is a PDF.');

        // 5. Email through SES: one per invoice, to the customer, as the delivery attempts recorded.
        $attempts = DeliveryAttempt::query()->get();
        $sent = $attempts->where('outcome', EmailOutcome::Sent);
        $abandoned = $attempts->where('outcome', EmailOutcome::Abandoned)->count();
        $recipient = $sent->first()?->recipient;
        $emails = collect($cloud->sentEmails())->filter(fn (array $email): bool => is_string($recipient) && in_array($recipient, $email['to'], true));
        $perInvoice = $documents->map(fn (Document $document): int => $emails->filter(fn (array $email): bool => str_contains($email['subject'], ';'.$document->full_number.';'))->count())->all();
        $emailed = array_sum($perInvoice);
        $this->verify($sent->count() === $count && $sent->pluck('document_id')->unique()->count() === $count && $sent->every(fn (DeliveryAttempt $attempt): bool => $attempt->mailer === config('mail.default')), sprintf('%d emails sent through the %s mailer, one per invoice.', $sent->count(), (string) config('mail.default')));
        $this->verify($perInvoice !== [] && min($perInvoice) >= 1 && $emailed <= $count + $abandoned, sprintf('SES took %d emails for the customer, every invoice at least once (%d repeated after a function died during the call, which delivery at least once allows).', $emailed, max(0, $emailed - $count)));

        // 6. Keys in the cache table (the sessions and the rate limits are checked right after the
        // requests of fast, as they expire within minutes), the secrets and the signing key.
        $this->tables($cloud, ['cache' => 'dynamodb']);

        $secret = $cloud->secret();
        $identity = $cloud->secretValue((string) config('database.connections.mysql.secrets_id'));
        $this->verify(
            ($secret['APP_KEY'] ?? null) === config('app.key') && ! isset($secret['DB_PASSWORD']) && ($identity['username'] ?? null) === config('database.connections.mysql.username') && ($identity['password'] ?? null) === config('database.connections.mysql.password'),
            sprintf('APP_KEY of this function comes from the secret of the application, which holds nothing of the database, and its database credentials from the secret of its identity (%s).', (string) config('database.connections.mysql.username')),
        );
        $this->verify(KmsKeyStoreEntry::query()->count() === 1 && KeyStoreEntry::query()->count() === 0, 'The signing certificate of the company is in the KMS key store (envelope encryption), not in the local one.');

        // 7. The scheduler function runs the Laravel scheduler once a minute.
        $lastRun = SchedulerHeartbeat::lastRun();
        $this->verify($lastRun !== null && Carbon::now()->getTimestamp() - $lastRun <= 150, sprintf('The scheduler function ran the Laravel scheduler (%s).', $lastRun === null ? 'never' : (Carbon::now()->getTimestamp() - $lastRun).' s ago'));

        $this->newLine();
        $this->line(sprintf('   Time: %.1f s from the first issue until every invoice was accepted, delivered and notified (%.1f s of it in this last wait, after the functions died: the messages they held came back after the visibility timeout of their queue).', $elapsed, microtime(true) - $waitStarted));
    }

    /**
     * A synthetic customer of the company with an email, and a webhook subscription of the local receiver to
     * every event.
     */
    private function customerWithWebhooks(TemporaryCompany $temporary): Contact
    {
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
            'url' => app(LocalWebhookReceiver::class)->urlFor($temporary->company->id),
            'events' => WebhookEventTypes::all(),
            'description' => 'make cloud-check',
        ], $temporary->user);

        return $customer;
    }

    /**
     * The two identities of the database: this function connects as the migrations identity, which holds the
     * schema; the application identity, which every other function connects as, cannot change it.
     */
    private function identities(EmulatedCloud $cloud): int
    {
        $database = DB::connection()->getDatabaseName();
        $migrations = (string) config('database.connections.mysql.username');
        $current = DB::selectOne('SELECT CURRENT_USER() AS user');
        $tls = ApplicationIdentityCommand::firstColumn([DB::selectOne("SHOW SESSION STATUS LIKE 'Ssl_version'")], 1)[0] ?? '';
        $grants = ApplicationIdentityCommand::firstColumn(DB::select('SHOW GRANTS'));
        $batches = DB::table('migrations')->count();
        $this->verify(
            is_object($current) && ($current->user ?? null) === "{$migrations}@%" && $tls !== '' && in_array("GRANT ALL PRIVILEGES ON `{$database}`.* TO `{$migrations}`@`%` WITH GRANT OPTION", $grants, true),
            sprintf('The console function connects as the migrations identity %s, over %s, which holds the schema of %s: the %d migrations ran with it.', $migrations, $tls === '' ? 'NO TLS' : $tls, $database, $batches),
        );

        $secret = config('database.application_identity.secret');
        $application = is_string($secret) ? $cloud->secretValue($secret) : [];
        $user = is_string($application['username'] ?? null) ? $application['username'] : '';
        $password = is_string($application['password'] ?? null) ? $application['password'] : '';
        $mysql = (array) config('database.connections.mysql');
        config(['database.connections.cloud-check-application' => [...$mysql, 'username' => $user, 'password' => $password, 'secrets_id' => null]]);
        $events = 0;
        $refused = [];

        try {
            $connection = DB::connection('cloud-check-application');
            $events = $connection->table('outbox_events')->count();

            // Only statements that would harm nothing if they ran: the identity must be refused each one.
            foreach (['CREATE TABLE cloud_check_probe (id INT)', 'ALTER TABLE outbox_events ADD COLUMN cloud_check_probe INT', 'DROP TABLE IF EXISTS cloud_check_probe'] as $statement) {
                try {
                    $connection->unprepared($statement);
                    $refused[$statement] = 'ran';
                } catch (QueryException $exception) {
                    $refused[$statement] = str_contains($exception->getMessage(), '1142') ? 'refused' : $exception->getMessage();
                }
            }
        } finally {
            DB::purge('cloud-check-application');
        }

        $this->verify(array_unique(array_values($refused)) === ['refused'], sprintf('The application identity %s reads and writes the data (%d outbox events) and cannot change the schema: %s.', $user, $events, implode('; ', array_map(fn (string $statement, string $outcome): string => "{$statement}: {$outcome}", array_keys($refused), $refused))));

        // TLS is required of it: without TLS the database refuses it.
        config(['database.connections.cloud-check-application' => [...$mysql, 'username' => $user, 'password' => $password, 'secrets_id' => null, 'options' => []]]);

        try {
            DB::connection('cloud-check-application')->select('SELECT 1');
            $plain = 'took it';
        } catch (QueryException $exception) {
            $plain = RefreshingMySqlConnector::refused($exception->getPrevious() ?? $exception) ? 'refused it' : $exception->getMessage();
        } finally {
            DB::purge('cloud-check-application');
        }

        $this->verify($plain === 'refused it', "Without TLS the database {$plain}.");

        return self::SUCCESS;
    }

    /**
     * Two rotations of the application identity with the functions warm, the way the rotation function of AWS
     * does with alternating users, with requests and invoices before and after each.
     */
    private function rotation(EmulatedCloud $cloud): int
    {
        $secret = config('database.application_identity.secret');

        if (! is_string($secret) || $secret === '') {
            $this->recordFailure('DB_APPLICATION_SECRETS_ID names no secret.');

            return self::SUCCESS;
        }

        $temporary = TemporaryCompany::create('Cloud Rotation', 'CRT');

        try {
            $customer = $this->customerWithWebhooks($temporary);
            [, $key] = app(ApiKeys::class)->create('Cloud check rotation (read and write)', ApiKeyLevel::ReadWrite, creator: $temporary->user);
            $failed = DB::table('failed_jobs')->count();

            $this->traffic($temporary, $customer, $key, 'Before the rotations, warming the functions with the credentials they start with');

            [$from, $to] = $this->rotate($cloud, $secret);
            $this->line("   Rotation 1: the secret moved from {$from} to {$to}, with a new password; {$from} keeps its own until the next rotation.");
            $this->traffic($temporary, $customer, $key, 'After the first rotation: the warm functions keep the credentials of before, still valid');

            $since = (int) floor(microtime(true) * 1000);
            [$from, $to] = $this->rotate($cloud, $secret);
            $this->line("   Rotation 2: the secret moved from {$from} to {$to}, which got a new password: the warm functions that hold its old one must read their secret again.");
            $this->traffic($temporary, $customer, $key, 'After the second rotation');

            sleep(5);
            $refreshes = [];

            foreach (['api', 'chain', 'webhooks', 'relay', 'scheduler'] as $function) {
                $refreshes[$function] = $cloud->logEvents("dian-einvoice-local-{$function}", 'they were read again from Secrets Manager', $since);
            }

            $this->verify(array_sum($refreshes) > 0, sprintf('The warm functions whose password the rotation replaced read their secret again and retried once: %s (log lines since the second rotation).', implode(', ', array_map(fn (string $function, int $count): string => "{$function} {$count}", array_keys($refreshes), $refreshes))));
            $this->verify(DB::table('failed_jobs')->count() === $failed, sprintf('No job failed during the rotations (%d failed jobs before and after).', $failed));

            foreach (OutboxSubscribers::CHANNELS as $channel) {
                $dlq = config("aws.dead_letter_queues.{$channel}");
                $this->verify(array_sum($cloud->depth(is_string($dlq) ? $dlq : '')) === 0, "The dead-letter queue of {$channel} is still empty.");
            }

            $lastRun = SchedulerHeartbeat::lastRun();
            $this->verify($lastRun !== null && Carbon::now()->getTimestamp() - $lastRun <= 150, sprintf('The scheduler function still runs once a minute (%s).', $lastRun === null ? 'never' : (Carbon::now()->getTimestamp() - $lastRun).' s ago'));
        } finally {
            $temporary->delete();
            $this->line('Temporary company deleted.');
        }

        return self::SUCCESS;
    }

    /**
     * A rotation of the application identity as the rotation function of AWS does it with alternating users
     * (createSecret, setSecret, testSecret, finishSecret), with the migrations identity of this function as its
     * superuser: the user that is not current gets a new password, and the privileges of the identity when it
     * is created; the new credentials are tested; and the secret moves to it, the old version becoming
     * AWSPREVIOUS.
     *
     * @return array{string, string} The user the secret moved from, and the one it moved to.
     */
    private function rotate(EmulatedCloud $cloud, string $secret): array
    {
        $value = $cloud->secretValue($secret);
        $current = is_string($value['username'] ?? null) ? $value['username'] : '';
        $other = str_ends_with($current, '_clone') ? substr($current, 0, -6) : "{$current}_clone";

        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $other) !== 1) {
            throw new RuntimeException("{$other} is not a plain MySQL user name.");
        }

        // Letters and digits only, and a name checked above: account management takes no bound parameters.
        $password = Str::password(30, symbols: false);
        $database = DB::connection()->getDatabaseName();
        $pdo = DB::connection()->getPdo();
        $pdo->exec("CREATE USER IF NOT EXISTS '{$other}'@'%' IDENTIFIED BY '{$password}' REQUIRE SSL");
        $pdo->exec("ALTER USER '{$other}'@'%' IDENTIFIED BY '{$password}' REQUIRE SSL");
        $pdo->exec(sprintf("GRANT %s ON `%s`.* TO '%s'@'%%'", implode(', ', ApplicationIdentityCommand::PRIVILEGES), $database, $other));

        config(['database.connections.cloud-check-pending' => [...(array) config('database.connections.mysql'), 'username' => $other, 'password' => $password, 'secrets_id' => null]]);

        try {
            DB::connection('cloud-check-pending')->select('SELECT 1');
        } finally {
            DB::purge('cloud-check-pending');
        }

        $cloud->putSecret($secret, [...$value, 'username' => $other, 'password' => $password]);

        return [$current, $other];
    }

    /**
     * Requests through the edge and API Gateway (health, which reads the database, and a listing with an API key) and two
     * invoices drafted and issued through the API, taken through the chain and the webhooks by their functions:
     * every answer must be a success and every invoice end accepted, delivered and notified.
     */
    private function traffic(TemporaryCompany $temporary, Contact $customer, string $key, string $when): void
    {
        $api = rtrim((string) config('developers.api_check_url'), '/');
        $client = fn (): PendingRequest => Http::withToken($key)->acceptJson()->asJson()->connectTimeout(5)->timeout(30);
        $statuses = [];

        for ($request = 0; $request < 10; $request++) {
            $statuses[] = Http::acceptJson()->connectTimeout(5)->timeout(30)->get("{$api}/health")->status();
        }

        $statuses[] = $client()->get("{$api}/invoices", ['per_page' => 1])->status();
        $ids = [];

        for ($invoice = 0; $invoice < 2; $invoice++) {
            $draft = $client()->post("{$api}/invoices", [
                'contact_id' => $customer->id,
                'number_template_id' => $temporary->numbering()->id,
                'lines' => [['description' => 'Cloud rotation service', 'quantity' => '1', 'unit_price' => '25000', 'tax_id' => $temporary->iva()]],
            ]);
            $statuses[] = $draft->status();
            $id = $draft->json('data.id', $draft->json('id'));

            if (is_string($id)) {
                $issue = $client()->withHeaders([HandleIdempotency::HEADER => (string) Str::uuid()])->post("{$api}/invoices/{$id}/issue");
                $statuses[] = $issue->status();
                $ids[] = $id;
            }
        }

        if ($ids !== []) {
            $this->waitForChain($temporary->company->id, $ids);
        }

        $documents = Document::query()->whereKey($ids)->get();
        $done = $documents->filter(fn (Document $document): bool => $document->electronic_status?->value === 'accepted' && $document->delivery_status === DeliveryStatus::Delivered)->count();
        $hooks = WebhookDelivery::query()->get();
        $errors = array_values(array_filter($statuses, fn (int $status): bool => $status >= 400));

        $this->verify(
            $errors === [] && count($ids) === 2 && $done === 2 && $hooks->every(fn (WebhookDelivery $delivery): bool => $delivery->status === WebhookDeliveryStatus::Succeeded),
            sprintf('%s: %d requests through the edge answered %s; the 2 invoices issued through the API ended accepted and delivered (%d), and %d webhooks arrived.', $when, count($statuses), $errors === [] ? 'without an error' : 'errors '.implode(', ', $errors), $done, $hooks->where('status', WebhookDeliveryStatus::Succeeded)->count()),
        );
    }

    /**
     * Waits until the given invoices (every issued one when null) are accepted or failed for good and
     * delivered, no webhook is pending and no outbox event of the company is unpublished.
     *
     * @param  list<string>|null  $ids
     */
    private function waitForChain(string $companyId, ?array $ids): void
    {
        $timeout = max(1, (int) $this->option('timeout'));
        $started = microtime(true);
        $this->line("Waiting up to {$timeout} s for every invoice to be accepted and delivered and every webhook to arrive...");

        do {
            $query = Document::query()->where('status', DocumentStatus::Issued);
            $documents = ($ids === null ? $query : $query->whereKey($ids))->get();
            $open = $documents->filter(fn (Document $document): bool => $document->electronic_status?->isFinal() !== true || $document->delivery_status === null || $document->delivery_status === DeliveryStatus::Pending)->count();
            $pendingHooks = WebhookDelivery::query()->where('status', WebhookDeliveryStatus::Pending)->count();
            $unpublished = OutboxEvent::query()->where('company_id', $companyId)->whereNull('published_at')->count();

            if ($documents->isNotEmpty() && $open === 0 && $pendingHooks === 0 && $unpublished === 0) {
                $this->line(sprintf('Done after %.1f s.', microtime(true) - $started));

                return;
            }

            usleep(500_000);
        } while (microtime(true) - $started < $timeout);

        $this->line(sprintf('Still open after %d s: %d invoices, %d webhooks, %d unpublished events.', $timeout, $open, $pendingHooks, $unpublished));
    }

    /**
     * @param  list<float>  $values  Sorted.
     */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        $rank = (int) ceil($percentile / 100 * count($values)) - 1;

        return $values[max(0, min(count($values) - 1, $rank))];
    }

    /**
     * @param  callable(TemporaryCompany): int  $action
     */
    private function withCompany(callable $action): int
    {
        $temporary = $this->temporary();

        return $temporary === null ? self::FAILURE : $action($temporary);
    }

    private function cleanup(): int
    {
        $this->temporary()?->delete();

        return self::SUCCESS;
    }

    /** The demo step (see the comment of the class). */
    private function demo(DianSimulator $simulator): int
    {
        $companies = Company::query()->whereIn('identification_number', IdentityDemoSeeder::NITS)->get();

        if ($companies->count() < count(IdentityDemoSeeder::NITS)) {
            $this->error('The demo data is not in the database of the emulated cloud. Run make cloud-demo, then make cloud-e2e again.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            /** @var list<string> $issued */
            $issued = Document::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->whereNotNull('full_number')->pluck('full_number')->all();
            $ahead = array_values(array_filter(
                $simulator->documents($company->identification_number),
                fn (array $record): bool => ! in_array(Json::text($record, 'number'), $issued, true),
            ));

            if ($ahead !== []) {
                $number = Json::text($ahead[0], 'number') ?? 'without a number';
                $this->error(count($ahead) === 1
                    ? sprintf('The local DIAN simulator holds a document of %s under a number this database has not issued yet, %s: the local stack issued it after make cloud-demo, and the next document here would take that number and be rejected with rule 90.', $company->name, $number)
                    : sprintf('The local DIAN simulator holds %d documents of %s under numbers this database has not issued yet, %s among them: the local stack issued them after make cloud-demo, and the next documents here would take those numbers and be rejected with rule 90.', count($ahead), $company->name, $number));
                $this->error('Run make cloud-demo, which loads the demo data again and clears the simulator, then make cloud-e2e again.');

                return self::FAILURE;
            }
        }

        $this->info('The demo data is in the database, and the local DIAN simulator holds no document of the demo companies under a number this database has not issued yet.');

        return self::SUCCESS;
    }

    private function temporary(): ?TemporaryCompany
    {
        $id = (string) $this->argument('company');
        $temporary = $id === '' ? null : TemporaryCompany::find($id);

        if ($temporary === null) {
            $this->error("There is no temporary company {$id}.");

            return null;
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

    /** Whether both queues are empty. */
    private function drained(EmulatedCloud $cloud): bool
    {
        foreach (OutboxSubscribers::CHANNELS as $channel) {
            $url = config("queue.connections.sqs.queues.{$channel}");

            if (array_sum($cloud->depth(is_string($url) ? $url : '')) > 0) {
                return false;
            }
        }

        return true;
    }

    /** An emulator difference the step allows for: not a failure. */
    private function note(string $message): void
    {
        $this->warn("   NOTE  {$message}");
    }

    /**
     * How many times a queue received a message, from a look at the queue.
     *
     * @param  list<array{id: string, receives: int}>  $messages
     */
    private function receivesOf(array $messages, string $messageId): int
    {
        foreach ($messages as $message) {
            if ($message['id'] === $messageId) {
                return $message['receives'];
            }
        }

        return 0;
    }

    private function recordFailure(string $message): void
    {
        $this->error("   FAIL  {$message}");
        $this->failures++;
    }

    private function key(string $companyId, string $what): string
    {
        return "cloud-check:{$what}:{$companyId}";
    }
}
