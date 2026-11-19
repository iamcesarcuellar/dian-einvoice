<?php

namespace App\Modules\Developers\Http\Controllers;

use App\Http\Listing;
use App\Modules\Developers\Http\Requests\WebhookSubscriptionRequest;
use App\Modules\Developers\Http\Resources\WebhookDeliveryResource;
use App\Modules\Developers\Http\Resources\WebhookSubscriptionResource;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookPublisher;
use App\Modules\Developers\Webhooks\WebhookSubscriptions;
use App\Modules\Identity\Tenancy\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Webhook subscriptions of the current company, for owners and admins in the web app. The secret
 * that signs the deliveries is shown once, when it is created or rotated.
 *
 * @phpstan-import-type SubscriptionInput from WebhookSubscriptions
 * @phpstan-import-type SubscriptionChanges from WebhookSubscriptions
 */
final class WebhookSubscriptionController
{
    /** The public events a subscription can receive, with what each one means. */
    public function eventTypes(): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (string $type, string $description): array => ['type' => $type, 'description' => $description],
            array_keys(WebhookEventTypes::TYPES),
            WebhookEventTypes::TYPES,
        )]);
    }

    /** Newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return WebhookSubscriptionResource::collection(Listing::paginate(WebhookSubscription::query()->orderByDesc('created_at'), $request, ['url', 'description']));
    }

    /** Create a subscription. The answer carries its secret in secret, once. */
    public function store(WebhookSubscriptionRequest $request, WebhookSubscriptions $subscriptions): JsonResponse
    {
        /** @var SubscriptionInput $input */
        $input = $request->validated();
        [$subscription, $secret] = $subscriptions->create($input, Actor::user($request));

        return response()->json(['data' => [...WebhookSubscriptionResource::make($subscription)->resolve($request), 'secret' => $secret]], 201);
    }

    public function show(WebhookSubscription $subscription): WebhookSubscriptionResource
    {
        return WebhookSubscriptionResource::make($subscription);
    }

    /** Change the URL, the events or the description, or switch it on or off. */
    public function update(WebhookSubscriptionRequest $request, WebhookSubscription $subscription, WebhookSubscriptions $subscriptions): WebhookSubscriptionResource
    {
        /** @var SubscriptionChanges $input */
        $input = $request->validated();

        return WebhookSubscriptionResource::make($subscriptions->update($subscription, $input));
    }

    /** Delete a subscription with its deliveries. */
    public function destroy(WebhookSubscription $subscription, WebhookSubscriptions $subscriptions): Response
    {
        $subscriptions->delete($subscription);

        return response()->noContent();
    }

    /** A new secret replaces the current one at once. The answer carries it in secret, once. */
    public function rotateSecret(Request $request, WebhookSubscription $subscription, WebhookSubscriptions $subscriptions): JsonResponse
    {
        $secret = $subscriptions->rotateSecret($subscription);

        return response()->json(['data' => [...WebhookSubscriptionResource::make($subscription->refresh())->resolve($request), 'secret' => $secret]]);
    }

    /** Send a test event (webhook.test) to this subscription, signed like any other: 201 with its delivery. */
    public function test(WebhookSubscription $subscription, WebhookPublisher $publisher): JsonResponse
    {
        $delivery = $publisher->test($subscription);

        return WebhookDeliveryResource::make($delivery->load('event'))->response()->setStatusCode(201);
    }
}
