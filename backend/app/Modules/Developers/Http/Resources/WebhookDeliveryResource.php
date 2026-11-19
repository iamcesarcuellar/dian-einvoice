<?php

namespace App\Modules\Developers\Http\Resources;

use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * A delivery of one event to one subscription. The detail adds the request sent (URL, headers that
 * do not change between attempts, and the body) and every attempt with its answer.
 *
 * @mixin WebhookDelivery
 */
final class WebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscription_id' => $this->subscription_id,
            'event_id' => $this->relationLoaded('event') ? $this->loadedEvent()->publicId() : 'evt_'.$this->event_id,
            'event_type' => $this->event_type,
            // pending, succeeded or failed.
            'status' => $this->status,
            'attempts' => $this->attempts,
            'run' => $this->run,
            'last_status_code' => $this->last_status_code,
            'last_error' => $this->last_error,
            'last_attempt_at' => $this->last_attempt_at,
            'next_attempt_at' => $this->next_attempt_at,
            'succeeded_at' => $this->succeeded_at,
            'failed_at' => $this->failed_at,
            'created_at' => $this->created_at,
            'request' => $this->when($this->relationLoaded('event') && $this->relationLoaded('subscription'), fn (): array => [
                'url' => ($this->subscription ?? throw new LogicException("Delivery {$this->id} has no subscription."))->url,
                'headers' => [
                    'content-type' => 'application/json',
                    'webhook-id' => $this->loadedEvent()->publicId(),
                    'webhook-timestamp' => 'Unix time of each attempt',
                    'webhook-signature' => 'v1,{base64 HMAC-SHA256 of "{webhook-id}.{webhook-timestamp}.{body}"}',
                ],
                'body' => $this->loadedEvent()->payload,
            ]),
            'attempt_log' => $this->whenLoaded('attemptLog', fn () => $this->attemptLog->map(fn (WebhookAttempt $attempt): array => [
                'id' => $attempt->id,
                'run' => $attempt->run,
                'attempt' => $attempt->attempt,
                'url' => $attempt->url,
                'started_at' => $attempt->started_at,
                'finished_at' => $attempt->finished_at,
                'duration_ms' => $attempt->duration_ms,
                'status_code' => $attempt->status_code,
                'outcome' => $attempt->outcome,
                'error' => $attempt->error,
                'response_snippet' => $attempt->response_snippet,
            ])->values()->all()),
        ];
    }

    /** The loaded event of the delivery: deleting the event deletes it (foreign keys cascade). */
    private function loadedEvent(): WebhookEvent
    {
        return $this->event ?? throw new LogicException("Delivery {$this->id} has no event.");
    }
}
