<?php

namespace App\Modules\Developers\Console;

use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Http\Middleware\HandleIdempotency;
use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Receiver\ReceiverUnavailable;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookSubscriptions;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Support\Console\LocalOnly;
use App\Support\Json;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Development check of the public API, over HTTP only and with API keys, on a temporary company of
 * its own that is deleted at the end. The setup (the company, its two keys and the subscription of
 * the local webhook receiver) is written directly, since no key may manage keys or webhooks; every
 * check after it is an HTTP request to the API, plus what the local receiver says it got.
 */
final class ApiCheckCommand extends Command
{
    protected $signature = 'api:check';

    protected $description = 'Check the public API over HTTP with API keys on a temporary company: idempotency, key levels, webhooks with retries and the rate limit';

    /** Seconds to wait for the chain to accept and deliver an invoice, and for webhooks to arrive. */
    private const int CHAIN_TIMEOUT = 120;

    private const int WEBHOOK_TIMEOUT = 60;

    private string $base;

    private int $failures = 0;

    public function handle(ApiKeys $keys, WebhookSubscriptions $subscriptions, LocalWebhookReceiver $receiver): int
    {
        if (($refusal = LocalOnly::refusal($this->getName() ?? 'This command')) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        $this->base = rtrim((string) config('developers.api_check_url'), '/');
        $temporary = TemporaryCompany::create('API Check', 'APIC');
        $companyId = $temporary->company->id;

        try {
            [, $write] = $keys->create('API check (read and write)', ApiKeyLevel::ReadWrite, creator: $temporary->user);
            [, $read] = $keys->create('API check (read only)', ApiKeyLevel::ReadOnly, creator: $temporary->user);
            [$subscription] = $subscriptions->create([
                'url' => $receiver->urlFor($companyId),
                'events' => WebhookEventTypes::all(),
                'description' => 'make api-check',
            ], $temporary->user);

            $this->line(sprintf('Setup, outside HTTP: temporary company %s, a read_write and a read_only API key, and a subscription of the local webhook receiver to every event (%s).', $temporary->company->name, $subscription->url));
            $this->line("Every step below is an HTTP request to {$this->base} with an API key.");
            $this->newLine();

            $this->runChecks($write, $read, $companyId, $subscription->id, $receiver);
        } catch (Throwable $exception) {
            $this->recordFailure('the check stopped: '.$exception->getMessage());
        } finally {
            $temporary->delete();

            try {
                $receiver->forget($companyId);
            } catch (ReceiverUnavailable) {
                // The receiver keeps a few webhooks of a company that no longer exists; harmless.
            }

            $this->line('Temporary company deleted.');
        }

        $this->newLine();

        if ($this->failures > 0) {
            $this->error("api-check: {$this->failures} checks FAILED.");

            return self::FAILURE;
        }

        $this->info('api-check: every check passed.');

        return self::SUCCESS;
    }

    private function runChecks(string $write, string $read, string $companyId, string $subscriptionId, LocalWebhookReceiver $receiver): void
    {
        // 1. A contact, an item, a draft, and its issue with an Idempotency-Key.
        $contact = $this->expect($this->api($write)->post('/contacts', [
            'organization_type' => '1',
            'identification_type' => '31',
            'identification_number' => '990009001',
            'legal_name' => 'API Check Customer SAS (synthetic)',
            'tax_scheme' => '01',
            'fiscal_responsibilities' => ['R-99-PN'],
            'email' => 'api-check-customer@example.com',
            'address' => 'Calle 10 # 43-20',
            'municipality_code' => '05001',
        ]), 201, 'POST /contacts creates a contact');

        $tax = collect(Json::objects($this->api($read)->get('/taxes', ['per_page' => 100])->json(), 'data'))->firstWhere('name', 'IVA 19%');

        $item = $this->expect($this->api($write)->post('/items', [
            'code' => 'API-001',
            'name' => 'Integration support hour (synthetic)',
            'type' => 'service',
            'unit_code' => '94',
            'unit_price' => '100000',
            'tax_id' => $tax['id'] ?? null,
        ]), 201, 'POST /items creates an item');

        $draft = fn (): array => $this->expect($this->api($write)->post('/invoices', [
            'contact_id' => $contact['id'],
            'payment_form' => '2',
            'payment_means_code' => '47',
            'due_date' => Carbon::now(NumberTemplate::TIMEZONE)->addDays(30)->toDateString(),
            'lines' => [['item_id' => $item['id'], 'quantity' => '2']],
        ]), 201, 'POST /invoices creates a draft');

        $firstId = $this->id($draft());
        $key = (string) Str::uuid();
        $issued = $this->api($write)->withHeaders([HandleIdempotency::HEADER => $key])->post("/invoices/{$firstId}/issue");
        $invoice = $this->expect($issued, 200, 'POST /invoices/{id}/issue with an Idempotency-Key issues it');
        $invoiceNumber = Json::text($invoice, 'full_number') ?? '?';
        $this->detail(sprintf('invoice %s, status %s, CUFE %s…', $invoiceNumber, Json::text($invoice, 'status'), substr(Json::text($invoice, 'cufe') ?? '', 0, 16)));

        // 2. The same key and the same request: the kept answer, marked as a repetition.
        $again = $this->api($write)->withHeaders([HandleIdempotency::HEADER => $key])->post("/invoices/{$firstId}/issue");
        $this->check(
            $again->status() === $issued->status() && $again->body() === $issued->body() && $again->header(HandleIdempotency::REPLAYED) === 'true',
            sprintf('the same key again answers the same %d with the same body and %s: true', $again->status(), HandleIdempotency::REPLAYED),
            sprintf('got %d, %s: %s, same body: %s', $again->status(), HandleIdempotency::REPLAYED, $again->header(HandleIdempotency::REPLAYED) ?: 'missing', $again->body() === $issued->body() ? 'yes' : 'no'),
        );
        $total = Json::int($this->api($read)->get('/invoices')->json(), 'meta.total') ?? 0;
        $this->check($total === 1, 'there is still one invoice: nothing ran twice', "GET /invoices reports {$total} invoices");

        // 3. The same key with another body.
        $other = $this->api($write)->withHeaders([HandleIdempotency::HEADER => $key])->post("/invoices/{$firstId}/issue", ['notes' => 'Another body']);
        $this->expect($other, 422, 'the same key with another body answers 422');
        $this->detail(Json::text($other->json(), 'detail') ?? '');

        // 4. Ten issues of another draft at the same moment, with one key.
        $secondId = $this->id($draft());
        $concurrent = (string) Str::uuid();
        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (int $index) => $this->client($pool->as("issue-{$index}"), $write)->withHeaders([HandleIdempotency::HEADER => $concurrent])->post("/invoices/{$secondId}/issue"),
            range(1, 10),
        ));

        $ran = $replayed = $inProgress = $other = 0;

        foreach ($responses as $response) {
            match (true) {
                ! $response instanceof Response => $other++,
                $response->status() === 200 && $response->header(HandleIdempotency::REPLAYED) === 'true' => $replayed++,
                $response->status() === 200 => $ran++,
                $response->status() === 409 => $inProgress++,
                default => $other++,
            };
        }

        $this->check(
            $ran === 1 && $other === 0,
            "10 simultaneous issues with one key: it ran once ({$ran} executed, {$inProgress} answered 409 in progress, {$replayed} replayed)",
            "{$ran} executed, {$inProgress} answered 409, {$replayed} replayed, {$other} other answers",
        );

        $secondInvoice = Json::array($this->api($read)->get("/invoices/{$secondId}")->json(), 'data');
        $issueEvents = collect(Json::objects($secondInvoice, 'events'))->where('type', 'issued')->count();
        $issuedTotal = Json::int($this->api($read)->get('/invoices', ['status' => 'issued'])->json(), 'meta.total') ?? 0;
        $this->check(
            $issueEvents === 1 && $issuedTotal === 2 && (Json::int($secondInvoice, 'number') ?? 0) === (Json::int($invoice, 'number') ?? 0) + 1,
            sprintf('the draft was issued once: number %s, one issued entry in its timeline, two invoices issued in all', Json::text($secondInvoice, 'full_number') ?? '?'),
            "{$issueEvents} issued entries, {$issuedTotal} invoices issued, number ".(Json::text($secondInvoice, 'number') ?? 'none'),
        );
        $actor = collect(Json::objects($secondInvoice, 'events'))->firstWhere('type', 'issued')['actor'] ?? null;
        $this->check(Json::text($actor, 'type') === 'api_key', sprintf('its timeline names the key as the actor: %s', Json::text($actor, 'name') ?? '?'), 'the issued entry has actor '.json_encode($actor));

        // 5. A read-only key cannot write.
        $denied = $this->api($read)->post('/contacts', ['legal_name' => 'Should not exist']);
        $this->expect($denied, 403, 'a POST with the read_only key answers 403');
        $this->detail(Json::text($denied->json(), 'detail') ?? '');

        // 6. The webhooks of the first invoice, with a valid signature.
        $this->line('  … waiting for the chain to accept and deliver both invoices (XML, signature, local DIAN simulator, PDF and email)');
        $accepted = $this->waitFor(fn (): ?array => $this->delivered($read, $firstId), self::CHAIN_TIMEOUT);
        $this->check($accepted !== null, 'invoice '.$invoiceNumber.' accepted by the local DIAN simulator and delivered', 'it did not end accepted and delivered in '.self::CHAIN_TIMEOUT.' s');
        $this->waitFor(fn (): ?array => $this->delivered($read, $secondId), self::CHAIN_TIMEOUT);

        $wanted = ['invoice.issued', 'invoice.accepted', 'invoice.delivered'];
        $received = $this->waitFor(function () use ($receiver, $companyId, $firstId, $wanted): ?array {
            $found = collect($receiver->status($companyId, 100)['received'])
                ->filter(fn (array $hook): bool => ($hook['object_id'] ?? null) === $firstId && ($hook['response_status'] ?? null) === 200)
                ->keyBy('event_type');

            return collect($wanted)->every(fn (string $type): bool => $found->has($type)) ? $found->all() : null;
        }, self::WEBHOOK_TIMEOUT);

        foreach ($wanted as $type) {
            $hook = $received[$type] ?? null;
            $this->check(
                $hook !== null && ($hook['verified'] ?? false) === true,
                sprintf('the receiver got %s with a valid signature (webhook-id %s)', $type, Json::text($hook, 'webhook_id') ?? '?'),
                $hook === null ? "the receiver never got {$type}" : "{$type} arrived with verification ".Json::text($hook, 'verification'),
            );
        }

        // 7. Two answers 500 from the receiver, then a payment: payment.recorded arrives after the retries.
        // The last event of the second invoice must have reached the receiver first: until the relay
        // publishes it, it has no delivery yet, and it would take one of the two 500 answers.
        $this->waitFor(fn (): ?bool => collect($receiver->status($companyId, 100)['received'])
            ->contains(fn (array $hook): bool => ($hook['object_id'] ?? null) === $secondId && ($hook['event_type'] ?? null) === 'invoice.delivered' && ($hook['response_status'] ?? null) === 200) ?: null, self::WEBHOOK_TIMEOUT);
        $this->waitFor(fn (): ?bool => WebhookDelivery::query()->withoutGlobalScope(CompanyScope::class)
            ->where('subscription_id', $subscriptionId)
            ->where('status', WebhookDeliveryStatus::Pending)
            ->doesntExist() ?: null, self::WEBHOOK_TIMEOUT);

        $receiver->setMode($companyId, ['mode' => 'error_500', 'count' => 2]);
        $this->detail('local receiver set to answer HTTP 500 to the next 2 deliveries');

        $payment = $this->expect($this->api($write)->withHeaders([HandleIdempotency::HEADER => (string) Str::uuid()])->post('/payments', [
            'contact_id' => $contact['id'],
            'payment_date' => Carbon::now(NumberTemplate::TIMEZONE)->toDateString(),
            'payment_means_code' => '47',
            'reference' => 'API-CHECK-001',
            'allocations' => [['document_id' => $firstId, 'amount' => '50000.00']],
        ]), 201, 'POST /payments records a payment of 50.000,00 on '.$invoiceNumber);

        $hooks = $this->waitFor(function () use ($receiver, $companyId, $payment): ?array {
            $hooks = collect($receiver->status($companyId, 100)['received'])
                ->filter(fn (array $hook): bool => ($hook['event_type'] ?? null) === 'payment.recorded' && ($hook['object_id'] ?? null) === $payment['id'])
                ->sortBy('received_at')
                ->values();

            return $hooks->contains(fn (array $hook): bool => ($hook['response_status'] ?? null) === 200) ? $hooks->all() : null;
        }, self::WEBHOOK_TIMEOUT) ?? [];

        $codes = array_map(fn (array $hook): string => Json::text($hook, 'response_status') ?? '?', $hooks);
        $last = end($hooks) ?: [];
        $sameId = count(array_unique(array_filter(array_map(fn (array $hook): ?string => Json::text($hook, 'webhook_id'), $hooks), is_string(...)))) === 1;
        $this->check(
            $codes === ['500', '500', '200'] && ($last['verified'] ?? false) === true && $sameId,
            'payment.recorded arrived after the retries, with a valid signature: the receiver answered '.implode(', ', $codes).' to the same webhook-id',
            'the receiver answered '.($codes === [] ? 'nothing' : implode(', ', $codes)).($sameId ? '' : ' with different webhook-ids'),
        );

        $attempts = WebhookAttempt::query()->withoutGlobalScope(CompanyScope::class)
            ->whereIn('delivery_id', WebhookDelivery::query()->withoutGlobalScope(CompanyScope::class)->where('subscription_id', $subscriptionId)->where('event_type', 'payment.recorded')->select('id'))
            ->orderBy('attempt')
            ->get();
        $this->detail('attempts recorded: '.$attempts->map(fn (WebhookAttempt $attempt): string => sprintf('#%d %s in %d ms at %s', $attempt->attempt, $attempt->status_code ?? $attempt->outcome?->value, $attempt->duration_ms, $attempt->started_at->format('H:i:s')))->implode(', '));

        // 8. Above the rate limit: 429 with Retry-After.
        $sent = 0;
        $limited = null;

        while ($limited === null && $sent < 400) {
            $batch = Http::pool(fn (Pool $pool): array => array_map(
                fn (int $index) => $this->client($pool->as("rate-{$index}"), $write)->get('/contacts', ['per_page' => 1]),
                range(1, 20),
            ));
            $sent += 20;

            foreach ($batch as $response) {
                if ($response instanceof Response && $response->status() === 429) {
                    $limited = $response;

                    break;
                }
            }
        }

        $this->check(
            $limited !== null && (int) $limited->header('Retry-After') > 0 && $limited->header('X-RateLimit-Remaining') === '0',
            sprintf('above the limit the API answers 429 with Retry-After: %s s (X-RateLimit-Limit %s, X-RateLimit-Remaining %s) after %d more requests', $limited?->header('Retry-After'), $limited?->header('X-RateLimit-Limit'), $limited?->header('X-RateLimit-Remaining'), $sent),
            $limited === null ? "no 429 after {$sent} requests" : 'the 429 lacks Retry-After or the limit headers',
        );

        if ($limited !== null) {
            $this->detail(Json::text($limited->json(), 'detail') ?? '');
        }
    }

    /**
     * The invoice when it is accepted and delivered; null meanwhile.
     *
     * @return array<mixed>|null
     */
    private function delivered(string $key, string $id): ?array
    {
        $invoice = Json::array($this->api($key)->get("/invoices/{$id}")->json(), 'data');

        return data_get($invoice, 'electronic.status') === 'accepted' && data_get($invoice, 'delivery.status') === 'delivered' ? $invoice : null;
    }

    /**
     * The id of a resource the API answered with; the check stops without it.
     *
     * @param  array<mixed>  $resource
     */
    private function id(array $resource): string
    {
        return Json::text($resource, 'id') ?? throw new RuntimeException('The answer has no id.');
    }

    private function api(string $key): PendingRequest
    {
        return $this->client(Http::baseUrl($this->base), $key);
    }

    private function client(PendingRequest $request, string $key): PendingRequest
    {
        return $request->baseUrl($this->base)->withToken($key)->acceptJson()->asJson()->connectTimeout(5)->timeout(30);
    }

    /**
     * The data of the answer.
     *
     * @return array<mixed>
     */
    private function expect(Response $response, int $status, string $label): array
    {
        $this->check($response->status() === $status, "{$label}: {$status}", "{$label}: expected {$status}, got {$response->status()} ".Str::limit(Json::text($response->json(), 'detail') ?? $response->body(), 300));

        return Json::array($response->json(), 'data');
    }

    private function check(bool $passed, string $label, string $failure): void
    {
        if ($passed) {
            $this->line("  <info>✓</info> {$label}");

            return;
        }

        $this->recordFailure($failure);
    }

    private function recordFailure(string $message): void
    {
        $this->failures++;
        $this->line("  <error>✗ FAIL</error> {$message}");
    }

    private function detail(string $message): void
    {
        $this->line("      <fg=gray>{$message}</>");
    }

    /**
     * Polls once a second until the callback returns something other than null, or the time runs out.
     *
     * @template T
     *
     * @param  Closure(): (T|null)  $callback
     * @return T|null
     */
    private function waitFor(Closure $callback, int $seconds): mixed
    {
        $deadline = microtime(true) + $seconds;

        do {
            $result = $callback();

            if ($result !== null) {
                return $result;
            }

            usleep(1_000_000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
