<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Receiver\ReceiverUnavailable;
use App\Modules\Identity\Models\User;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Webhook subscriptions of the current company: created with a new secret shown once, edited,
 * switched on or off, given a new secret, deleted, and their deliveries sent again by hand. A
 * subscription that points to the local webhook receiver hands it its secret, so the receiver can
 * verify the signatures (best effort: the receiver is a development tool).
 */
final class WebhookSubscriptions
{
    public function __construct(
        private readonly LocalWebhookReceiver $receiver,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array{url: string, events: list<string>, description?: string|null, is_active?: bool}  $input
     * @return array{0: WebhookSubscription, 1: string} The subscription and its secret.
     */
    public function create(array $input, ?User $creator = null): array
    {
        $secret = WebhookSignature::generateSecret();

        $subscription = WebhookSubscription::create([
            'url' => $input['url'],
            'description' => $input['description'] ?? null,
            'events' => array_values(array_unique($input['events'])),
            'is_active' => $input['is_active'] ?? true,
            'secret' => $secret,
            'created_by' => $creator?->getKey(),
        ]);

        $this->shareSecret($subscription);

        return [$subscription, $secret];
    }

    /**
     * @param  array{url?: string, events?: list<string>, description?: string|null, is_active?: bool}  $input
     */
    public function update(WebhookSubscription $subscription, array $input): WebhookSubscription
    {
        $previousUrl = $subscription->url;

        if (array_key_exists('events', $input)) {
            $input['events'] = array_values(array_unique($input['events']));
        }

        $subscription->fill($input);

        // Switched on again by hand: its failures in a row start over.
        if (($input['is_active'] ?? false) === true && $subscription->isDirty('is_active')) {
            $subscription->forceFill(['consecutive_failures' => 0, 'disabled_at' => null, 'disabled_reason' => null]);
        }

        $subscription->save();

        if ($previousUrl !== $subscription->url) {
            $this->forgetSecret($subscription, $previousUrl);
            $this->shareSecret($subscription);
        }

        return $subscription;
    }

    /** A new secret replaces the old one at once; it is shown only now. */
    public function rotateSecret(WebhookSubscription $subscription): string
    {
        $secret = WebhookSignature::generateSecret();

        $subscription->forceFill(['secret' => $secret, 'secret_rotated_at' => Carbon::now()])->save();
        $this->shareSecret($subscription);

        return $secret;
    }

    public function delete(WebhookSubscription $subscription): void
    {
        $subscription->delete();
        $this->forgetSecret($subscription, $subscription->url);
    }

    /** A delivery that ended, sent again: a new run with its own attempts. 409 while pending. */
    public function redeliver(WebhookDelivery $delivery): WebhookDelivery
    {
        return DB::transaction(function () use ($delivery): WebhookDelivery {
            $delivery = WebhookDelivery::query()->with('subscription')->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if ($delivery->status === WebhookDeliveryStatus::Pending) {
                throw new ConflictHttpException('This delivery is still in progress.');
            }

            if (! $delivery->subscription->is_active && $delivery->event_type !== WebhookEventTypes::TEST) {
                throw new ConflictHttpException('The subscription is switched off. Activate it first.');
            }

            $delivery->fill([
                'status' => WebhookDeliveryStatus::Pending,
                'run' => $delivery->run + 1,
                'run_attempts' => 0,
                'next_attempt_at' => null,
                'succeeded_at' => null,
                'failed_at' => null,
            ])->save();

            $this->outbox->record($delivery->company_id, 'webhook_delivery', $delivery->getKey(), WebhookPublisher::DELIVERY_DUE, [
                'delivery_id' => $delivery->getKey(),
                'run' => $delivery->run,
                'attempt' => $delivery->attempts + 1,
            ]);

            return $delivery;
        });
    }

    private function shareSecret(WebhookSubscription $subscription): void
    {
        $companyId = $this->receiver->companyOf($subscription->url);

        if ($companyId === null) {
            return;
        }

        try {
            $this->receiver->registerSecret($companyId, $subscription->getKey(), $subscription->secret);
        } catch (ReceiverUnavailable $exception) {
            report($exception);
        }
    }

    private function forgetSecret(WebhookSubscription $subscription, string $url): void
    {
        $companyId = $this->receiver->companyOf($url);

        if ($companyId === null) {
            return;
        }

        try {
            $this->receiver->forgetSecret($companyId, $subscription->getKey());
        } catch (ReceiverUnavailable $exception) {
            report($exception);
        }
    }
}
