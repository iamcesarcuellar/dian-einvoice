<?php

namespace App\Modules\Developers\Http\Requests;

use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Webhooks\PrivateTargets;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use App\Modules\Developers\Webhooks\WebhookSender;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A webhook subscription, created or changed: an http or https URL (https on port 443 only in production,
 * WEBHOOK_REQUIRE_HTTPS), the public events it receives and an optional description; is_active switches it
 * on or off. Private destinations are refused unless they are allowed (WEBHOOK_ALLOW_PRIVATE_TARGETS=true,
 * local development only). A refused URL answers 422 in application/problem+json, with the reason in
 * errors.url; the sender checks the same again before every attempt, for subscriptions saved before.
 */
final class WebhookSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->route('subscription') instanceof WebhookSubscription ? ['sometimes', 'required'] : ['required'];

        return [
            'url' => [...$required, 'string', 'max:2048', (bool) config('developers.webhooks.require_https', false) ? 'url:https' : 'url:http,https', function (string $attribute, mixed $value, Closure $fail): void {
                // Where https is required (production), on its own port: the only one the functions may open
                // toward the internet (the security group of the functions, docs/infrastructure.md).
                if ((bool) config('developers.webhooks.require_https', false) && is_string($value) && PrivateTargets::port($value) !== WebhookSender::HTTPS_PORT) {
                    $fail(WebhookSender::HTTPS_PORT_ONLY);

                    return;
                }

                if (! (bool) config('developers.webhooks.allow_private_targets', false) && is_string($value) && ($reason = PrivateTargets::reason($value)) !== null) {
                    $fail($reason);
                }
            }],
            'events' => [...$required, 'array', 'min:1'],
            'events.*' => ['string', 'distinct', Rule::in(WebhookEventTypes::all())],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
