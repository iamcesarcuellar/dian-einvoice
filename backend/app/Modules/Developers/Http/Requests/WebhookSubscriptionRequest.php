<?php

namespace App\Modules\Developers\Http\Requests;

use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Webhooks\PrivateTargets;
use App\Modules\Developers\Webhooks\WebhookEventTypes;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A webhook subscription: an http or https URL, the public events it receives and an optional
 * description; is_active switches it on or off. Private destinations are refused when they are not
 * allowed (WEBHOOK_ALLOW_PRIVATE_TARGETS=false).
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
            'url' => [...$required, 'string', 'max:2048', 'url:http,https', function (string $attribute, mixed $value, Closure $fail): void {
                if (! (bool) config('developers.webhooks.allow_private_targets', true) && is_string($value) && ($reason = PrivateTargets::reason($value)) !== null) {
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
