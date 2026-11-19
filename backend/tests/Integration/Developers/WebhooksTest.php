<?php

namespace Tests\Integration\Developers;

use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use Tests\IntegrationTestCase;

/**
 * Webhooks to the local receiver of the simulator, which verifies their Standard Webhooks
 * signature: delivery, retries, the switch-off after failures in a row, and sending again.
 */
final class WebhooksTest extends IntegrationTestCase
{
    public function test_a_delivery_is_signed_and_retried_after_server_errors(): void
    {
        $company = $this->company();
        $subscription = $this->subscribe($company, ['invoice.issued']);
        $this->receiver()->setMode($company->company->id, ['mode' => 'error_500', 'count' => 2]);

        $delivery = $this->text($this->signedIn($company->user, $company)->postJson("/api/v1/webhooks/{$subscription->id}/test")->assertSuccessful()->json('data.id'));
        $this->drainOutbox($company);

        $this->assertSame(WebhookDeliveryStatus::Succeeded, WebhookDelivery::query()->findOrFail($delivery)->status);
        $this->assertSame([500, 500, 200], WebhookAttempt::query()->where('delivery_id', $delivery)->orderBy('attempt')->pluck('status_code')->all());
        $received = $this->receiver()->status($company->company->id)['received'];
        $this->assertTrue($received[0]['verified'] ?? null, 'The receiver did not verify the signature of the webhook that got through.');
    }

    public function test_failed_deliveries_in_a_row_switch_the_subscription_off_and_a_delivery_can_be_sent_again(): void
    {
        config(['developers.webhooks.disable_after_failures' => 2, 'developers.webhooks.retry_delays' => [1]]);
        $company = $this->company();
        $subscription = $this->subscribe($company, ['invoice.issued']);
        $this->receiver()->setMode($company->company->id, ['mode' => 'error_500']);

        foreach ([1, 2] as $invoice) {
            $this->issue($company, $this->draftInvoice($company));
        }

        $this->drainOutbox($company);

        $deliveries = WebhookDelivery::query()->where('subscription_id', $subscription->id)->get();
        $this->assertSame([WebhookDeliveryStatus::Failed], $deliveries->pluck('status')->unique()->values()->all());
        $this->assertCount(2, $deliveries);
        $subscription->refresh();
        $this->assertFalse($subscription->is_active);
        $this->assertSame(2, $subscription->consecutive_failures);

        // Switched off: nothing more is queued for it, and a delivery waits until it is active again.
        $this->issue($company, $this->draftInvoice($company));
        $this->drainOutbox($company);
        $this->assertSame(2, WebhookDelivery::query()->where('subscription_id', $subscription->id)->count());
        $api = $this->signedIn($company->user, $company);
        $first = $deliveries->firstOrFail();
        $api->postJson("/api/v1/webhook-deliveries/{$first->id}/redeliver")->assertStatus(409);

        $this->receiver()->setMode($company->company->id, ['mode' => 'normal']);
        $api->patchJson("/api/v1/webhooks/{$subscription->id}", ['is_active' => true])->assertOk();
        $api->postJson("/api/v1/webhook-deliveries/{$first->id}/redeliver")->assertSuccessful();
        $this->drainOutbox($company);

        $redelivered = $first->refresh();
        $this->assertSame(WebhookDeliveryStatus::Succeeded, $redelivered->status);
        $this->assertSame(2, $redelivered->run);
        $this->assertSame(0, $subscription->refresh()->consecutive_failures);
    }

    /**
     * A subscription of the company to its own local receiver, through the API.
     *
     * @param  list<string>  $events
     */
    private function subscribe(TemporaryCompany $company, array $events): WebhookSubscription
    {
        $id = $this->text($this->signedIn($company->user, $company)->postJson('/api/v1/webhooks', [
            'url' => $this->receiver()->urlFor($company->company->id),
            'events' => $events,
            'description' => 'Synthetic subscription of the integration tests',
        ])->assertCreated()->json('data.id'));

        $this->actingIn($company);

        return WebhookSubscription::query()->whereKey($id)->firstOrFail();
    }

    private function receiver(): LocalWebhookReceiver
    {
        return app(LocalWebhookReceiver::class);
    }
}
