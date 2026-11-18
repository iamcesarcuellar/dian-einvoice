<?php

namespace App\Modules\Developers\Http\Controllers;

use App\Http\Listing;
use App\Modules\Developers\Http\Resources\WebhookDeliveryResource;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Webhooks\WebhookSubscriptions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Deliveries of each subscription: the event, the status, the attempts, the last code and time,
 * the body sent and every answer; and Redeliver for one that ended.
 */
final class WebhookDeliveryController
{
    /** Newest first. ?status=pending|succeeded|failed, ?search= (event type). */
    public function index(Request $request, WebhookSubscription $subscription): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['sometimes', 'nullable', Rule::in(['pending', 'succeeded', 'failed'])]]);

        $query = WebhookDelivery::query()
            ->with('event')
            ->where('subscription_id', $subscription->getKey())
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return WebhookDeliveryResource::collection(Listing::paginate($query, $request, ['event_type']));
    }

    /** A delivery with the request sent and every attempt. */
    public function show(WebhookDelivery $delivery): WebhookDeliveryResource
    {
        return WebhookDeliveryResource::make($delivery->load(['event', 'subscription', 'attemptLog']));
    }

    /** Send it again, with new attempts: 409 while it is still pending or the subscription is off. */
    public function redeliver(WebhookDelivery $delivery, WebhookSubscriptions $subscriptions): WebhookDeliveryResource
    {
        return WebhookDeliveryResource::make($subscriptions->redeliver($delivery)->load(['event', 'subscription', 'attemptLog']));
    }
}
