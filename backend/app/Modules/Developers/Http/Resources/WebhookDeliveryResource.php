<?php

namespace App\Modules\Developers\Http\Resources;

use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'event_id' => $this->relationLoaded('event') ? $this->event->publicId() : 'evt_'.$this->event_id,
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
                'url' => $this->subscription->url,
                'headers' => [
                    'content-type' => 'application/json',
                    'webhook-id' => $this->event->publicId(),
                    'webhook-timestamp' => 'Unix time of each attempt',
                    'webhook-signature' => 'v1,{base64 HMAC-SHA256 of "{webhook-id}.{webhook-timestamp}.{body}"}',
                ],
                'body' => $this->event->payload,
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
}
