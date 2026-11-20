<?php

namespace Tests\Integration\Developers;

use App\Modules\Developers\Enums\WebhookAttemptOutcome;
use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Developers\Webhooks\WebhookSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\IntegrationTestCase;

/**
 * Where a webhook may go. With private targets refused (the default outside local development) a
 * URL that names or resolves to a private, local, link-local or metadata address is refused when
 * the subscription is saved and again before every attempt, so one saved while allowed never
 * reaches its address; with them allowed (local development) the local receiver gets it. In
 * production only https endpoints on port 443, and a redirect is never followed.
 */
final class WebhookTargetsTest extends IntegrationTestCase
{
    public function test_private_destinations_are_refused_when_saved_and_before_every_attempt(): void
    {
        $company = $this->company();
        $receiver = app(LocalWebhookReceiver::class)->urlFor($company->company->id);
        $api = fn () => $this->signedIn($company->user, $company);

        // Allowed, as in local development: the local receiver on the internal network.
        $subscription = $this->text($api()->postJson('/api/v1/webhooks', ['url' => $receiver, 'events' => ['invoice.issued']])->assertCreated()->json('data.id'));

        config(['developers.webhooks.allow_private_targets' => false]);

        $refused = [
            $receiver,
            'http://127.0.0.1:8000/api/v1/health',
            'http://localhost/hooks',
            'http://10.0.0.1/hooks',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.100.100.200/latest/meta-data/',
            'http://[::1]/hooks',
            'http://[::ffff:127.0.0.1]/hooks',
            'http://[fd00:ec2::254]/latest/meta-data/',
            'http://2130706433/hooks',
        ];

        foreach ($refused as $url) {
            $api()->postJson('/api/v1/webhooks', ['url' => $url, 'events' => ['invoice.issued']])->assertStatus(422)->assertJsonValidationErrors('url');
        }

        // The subscription saved while allowed is checked again at the moment of sending.
        $delivery = $this->text($api()->postJson("/api/v1/webhooks/{$subscription}/test")->assertCreated()->json('data.id'));
        $this->drainOutbox($company);

        $this->assertSame(WebhookDeliveryStatus::Failed, WebhookDelivery::query()->findOrFail($delivery)->status);
        $outcomes = WebhookAttempt::query()->where('delivery_id', $delivery)->pluck('outcome')->unique()->values()->all();
        $this->assertSame([WebhookAttemptOutcome::Blocked], $outcomes);
        $this->assertSame([], app(LocalWebhookReceiver::class)->status($company->company->id)['received']);
    }

    public function test_only_https_endpoints_when_required_and_redirects_are_not_followed(): void
    {
        $company = $this->company();
        $api = fn () => $this->signedIn($company->user, $company);

        config(['developers.webhooks.require_https' => true]);
        $api()->postJson('/api/v1/webhooks', ['url' => 'http://hooks.example.com/dian', 'events' => ['invoice.issued']])->assertStatus(422)->assertJsonValidationErrors('url');
        $this->assertSame(WebhookAttemptOutcome::Blocked, app(WebhookSender::class)->send('http://hooks.example.com/dian', 'msg_synthetic', '{}', 'whsec_'.base64_encode(random_bytes(24)))->outcome);

        // And on port 443 only, the only one the functions may open toward the internet: refused when the
        // subscription is created and when it is changed, with the reason in a problem+json...
        $refused = fn (TestResponse $response): TestResponse => $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('detail', 'The given data was invalid.')
            ->assertJsonPath('errors.url', [WebhookSender::HTTPS_PORT_ONLY]);
        $refused($api()->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com:8443/dian', 'events' => ['invoice.issued']]));
        $saved = $this->text($api()->postJson('/api/v1/webhooks', ['url' => 'https://hooks.example.com:443/dian', 'events' => ['invoice.issued']])->assertCreated()->json('data.id'));
        $refused($api()->patchJson("/api/v1/webhooks/{$saved}", ['url' => 'https://hooks.example.com:8443/dian']));
        $this->assertSame('https://hooks.example.com:443/dian', WebhookSubscription::query()->findOrFail($saved)->url);

        // ...and, for a subscription saved before the rule, before every attempt.
        $blocked = app(WebhookSender::class)->send('https://hooks.example.com:8443/dian', 'msg_synthetic', '{}', 'whsec_'.base64_encode(random_bytes(24)));
        $this->assertSame([WebhookAttemptOutcome::Blocked, WebhookSender::HTTPS_PORT_ONLY], [$blocked->outcome, $blocked->error]);

        // The reference of the API says so, in its section on webhooks.
        $this->assertStringContainsString('in production, an https URL on port 443', (string) config('scramble.info.description'));

        // A redirect is answered as it is: one request, and an HTTP error.
        config(['developers.webhooks.require_https' => false]);
        Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $result = app(WebhookSender::class)->send('http://hooks.example.com/dian', 'msg_synthetic', '{}', 'whsec_'.base64_encode(random_bytes(24)));

        $this->assertSame(WebhookAttemptOutcome::HttpError, $result->outcome);
        $this->assertSame(302, $result->statusCode);
        Http::assertSentCount(1);
    }
}
