<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Developers\Enums\WebhookAttemptOutcome;
use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Observability\LogEvent;
use App\Support\Observability\Telemetry;
use App\Support\Outbox\ConsumesOutboxEvents;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * One attempt of a webhook delivery, as a queued job built from its outbox event, with the rules of
 * the rest of the chain (see SendDeliveryEmail):
 *
 * 1. Claim, in a transaction: lock the delivery and check it is pending in the run of the event and
 *    that the attempt is the next one, or one whose worker stopped during its call (never finished,
 *    older than the lease). The attempt is written before the call.
 * 2. Send, outside any transaction: POST with the Standard Webhooks headers, 5 seconds at most.
 * 3. Record, in a transaction: code, duration and a piece of the answer. A 2xx succeeds. Otherwise
 *    the next attempt waits 2, 5, 15, 45 and 120 s plus jitter; after the last one the delivery
 *    fails, and 20 failed deliveries in a row switch the subscription off.
 *
 * Delivery is at least once and in no particular order: integrations deduplicate by webhook-id.
 */
final class DeliverWebhook implements ConsumesOutboxEvents
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Attempts of the job for unexpected errors, a worker that died included. */
    public int $tries = 10;

    /** Seconds before the worker stops the job; below REDIS_QUEUE_RETRY_AFTER. */
    public int $timeout = 35;

    public function __construct(
        public readonly string $companyId,
        public readonly string $deliveryId,
        public readonly int $run,
        public readonly int $attempt,
    ) {}

    public static function fromOutboxEvent(OutboxEvent $event): self
    {
        return new self(
            companyId: $event->company_id,
            deliveryId: $event->payloadString('delivery_id') ?? $event->aggregate_id,
            run: $event->payloadInt('run') ?? 1,
            attempt: $event->payloadInt('attempt') ?? 1,
        );
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 2, 5, 10, 30];
    }

    public function handle(CurrentCompany $currentCompany, WebhookSender $sender, WebhookRetryPolicy $policy, Outbox $outbox): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        // In the language of the company's documents: the errors the delivery stores for its attempts.
        $company->documentLanguage()->run(fn () => $currentCompany->runAs($company, function () use ($sender, $policy, $outbox): void {
            $claim = DB::transaction(fn (): ?array => $this->claim($policy), 3);

            if ($claim === null) {
                return;
            }

            [$attempt, $url, $messageId, $body, $secret] = $claim;
            $result = $sender->send($url, $messageId, $body, $secret);

            DB::transaction(fn () => $this->record($attempt, $result, $policy, $outbox), 3);

            // Never the URL, the body or the answer: the endpoint and its data belong to the company.
            Telemetry::record(
                LogEvent::WebhookDelivery,
                sprintf('Webhook delivery attempt %d %s.', $attempt->attempt, $result->succeeded() ? 'succeeded' : "failed: {$result->outcome->value}"),
                outcome: $result->succeeded() ? 'success' : 'failure',
                durationMs: (float) $result->durationMs,
                detail: ['status' => $result->statusCode, 'attempt' => $attempt->attempt, 'result' => $result->outcome->value],
                level: $result->succeeded() ? 'info' : 'warning',
            );
        }));
    }

    /** The queue gave up on the job: the delivery fails, if it still waits for this attempt. */
    public function failed(?Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $company->documentLanguage()->run(fn () => app(CurrentCompany::class)->runAs($company, function () use ($exception): void {
            DB::transaction(function () use ($exception): void {
                $delivery = WebhookDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

                if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Pending || $delivery->run !== $this->run) {
                    return;
                }

                $delivery->fill([
                    'status' => WebhookDeliveryStatus::Failed,
                    'failed_at' => Carbon::now(),
                    'next_attempt_at' => null,
                    'last_error' => Str::limit(__('developers::webhooks.unexpected', ['error' => $exception?->getMessage() ?? __('developers::webhooks.job_failed')]), 497),
                ])->save();
            });
        }));
    }

    /**
     * @return array{0: WebhookAttempt, 1: string, 2: string, 3: string, 4: string}|null The attempt, URL, webhook-id, body and secret.
     */
    private function claim(WebhookRetryPolicy $policy): ?array
    {
        $delivery = WebhookDelivery::query()->with(['event', 'subscription'])->whereKey($this->deliveryId)->lockForUpdate()->first();

        if ($delivery === null || $delivery->status !== WebhookDeliveryStatus::Pending || $delivery->run !== $this->run) {
            return null;
        }

        $running = WebhookAttempt::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('run', $delivery->run)
            ->whereNull('finished_at')
            ->latest('started_at')
            ->first();

        if ($delivery->attempts === $this->attempt - 1 && $running === null) {
            // The next attempt.
        } elseif ($delivery->attempts === $this->attempt && $running !== null) {
            // The worker of this attempt stopped during its call: take over once it cannot be running.
            if ($running->started_at->gt(Carbon::now()->subSeconds($policy->lease()))) {
                return null;
            }

            $running->fill([
                'finished_at' => Carbon::now(),
                'outcome' => WebhookAttemptOutcome::Abandoned,
                'error' => __('developers::webhooks.interrupted'),
            ])->save();
        } else {
            return null;
        }

        // Both outlive the locked delivery: deleting either one deletes it (foreign keys cascade).
        $subscription = $delivery->subscription ?? throw new LogicException("Delivery {$delivery->id} has no subscription.");
        $event = $delivery->event ?? throw new LogicException("Delivery {$delivery->id} has no event.");

        // A subscription switched off meanwhile receives nothing more; a test event goes anyway.
        if (! $subscription->is_active && $event->type !== WebhookEventTypes::TEST) {
            $delivery->fill([
                'status' => WebhookDeliveryStatus::Failed,
                'failed_at' => Carbon::now(),
                'next_attempt_at' => null,
                'last_error' => __('developers::webhooks.switched_off'),
            ])->save();

            return null;
        }

        $firstOfAttempt = $delivery->attempts === $this->attempt - 1;

        $delivery->fill([
            'attempts' => $this->attempt,
            'run_attempts' => $firstOfAttempt ? $delivery->run_attempts + 1 : $delivery->run_attempts,
            'next_attempt_at' => null,
        ])->save();

        $attempt = WebhookAttempt::create([
            'delivery_id' => $delivery->getKey(),
            'run' => $delivery->run,
            'attempt' => $this->attempt,
            'url' => $subscription->url,
            'started_at' => Carbon::now(),
        ]);

        return [$attempt, $subscription->url, $event->publicId(), $event->payload, $subscription->secret];
    }

    private function record(WebhookAttempt $attempt, SendResult $result, WebhookRetryPolicy $policy, Outbox $outbox): void
    {
        $delivery = WebhookDelivery::query()->whereKey($attempt->delivery_id)->lockForUpdate()->first();
        $row = WebhookAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

        // Another worker took this attempt over as abandoned: its own call decides.
        if ($delivery === null || $row === null || $row->finished_at !== null) {
            return;
        }

        $now = Carbon::now();

        $row->fill([
            'finished_at' => $now,
            'duration_ms' => $result->durationMs,
            'status_code' => $result->statusCode,
            'outcome' => $result->outcome,
            'error' => $result->error,
            'response_snippet' => $result->snippet,
        ])->save();

        // A redelivery by hand moved the delivery on meanwhile.
        if ($delivery->status !== WebhookDeliveryStatus::Pending || $delivery->run !== $row->run || $delivery->attempts !== $row->attempt) {
            return;
        }

        $subscription = WebhookSubscription::query()->whereKey($delivery->subscription_id)->lockForUpdate()->first();
        $isTest = $delivery->event_type === WebhookEventTypes::TEST;

        $delivery->fill([
            'last_attempt_at' => $now,
            'last_status_code' => $result->statusCode,
            'last_error' => $result->error,
        ]);

        if ($result->succeeded()) {
            $delivery->fill(['status' => WebhookDeliveryStatus::Succeeded, 'succeeded_at' => $now, 'next_attempt_at' => null])->save();
            $this->touchSubscription($subscription, $now, 'succeeded', $result->statusCode, $isTest ? null : 0, $policy);

            return;
        }

        if ($delivery->run_attempts < $policy->maxAttempts()) {
            $delay = $policy->delay($delivery->run_attempts);
            $next = $now->copy()->addSeconds($delay);

            $delivery->fill(['next_attempt_at' => $next])->save();
            $outbox->record($delivery->company_id, 'webhook_delivery', $delivery->id, WebhookPublisher::DELIVERY_DUE, [
                'delivery_id' => $delivery->getKey(),
                'run' => $delivery->run,
                'attempt' => $delivery->attempts + 1,
            ], $next);
            $this->touchSubscription($subscription, $now, 'failed', $result->statusCode, null, $policy);

            return;
        }

        $delivery->fill(['status' => WebhookDeliveryStatus::Failed, 'failed_at' => $now, 'next_attempt_at' => null])->save();
        $failures = $subscription === null || $isTest ? null : $subscription->consecutive_failures + 1;
        $this->touchSubscription($subscription, $now, 'failed', $result->statusCode, $failures, $policy);
    }

    /**
     * The last delivery of the subscription and its failures in a row (null leaves them as they are);
     * at the threshold it is switched off, and says why.
     *
     * @param  int<0, max>|null  $failures
     */
    private function touchSubscription(?WebhookSubscription $subscription, Carbon $now, string $status, ?int $code, ?int $failures, WebhookRetryPolicy $policy): void
    {
        if ($subscription === null) {
            return;
        }

        $subscription->forceFill([
            'last_delivery_at' => $now,
            'last_delivery_status' => $status,
            'last_response_code' => $code,
        ]);

        if ($failures !== null) {
            $subscription->consecutive_failures = $failures;

            if ($failures >= $policy->disableAfter() && $subscription->is_active) {
                $subscription->forceFill([
                    'is_active' => false,
                    'disabled_at' => $now,
                    'disabled_reason' => __('developers::webhooks.disabled', ['failures' => $failures]),
                ]);
            }
        }

        $subscription->save();
    }
}
