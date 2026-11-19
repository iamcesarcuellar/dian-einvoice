<?php

namespace Tests\Integration\Security;

use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Dian\Models\DianSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\IntegrationTestCase;
use Tests\Support\WebBrowser;

/**
 * The whole flow with every log line kept, at debug level: sign-up, sign-in (a wrong password
 * too), a certificate upload (a wrong password too), an API key and a revoked one, a webhook
 * subscription whose receiver is down (the error is reported with its trace), an invoice through
 * the chain with the PIN and the technical key, a webhook delivery, an invitation and a password
 * reset. No password, key, PIN, technical key, token or secret appears in the log, whole or as the
 * start of a stack trace argument; neither do the secrets of the stack.
 */
final class SecretsInLogsTest extends IntegrationTestCase
{
    public function test_no_secret_reaches_the_logs(): void
    {
        $log = storage_path('logs/secrets-test.log');
        File::delete($log);
        config(['session.driver' => 'redis', 'logging.channels.single.path' => $log, 'logging.channels.single.level' => 'debug']);
        app('log')->forgetChannel('single');
        $secrets = [];

        try {
            // Sign-up and sign-in, with a failed attempt first.
            $secrets['password'] = Str::random(24);
            $browser = WebBrowser::at($this);
            $browser->json('POST', '/api/v1/auth/register', ['name' => 'Synthetic Owner', 'email' => 'synthetic-logs@example.com', 'password' => $secrets['password'], 'company' => ['name' => 'Synthetic Logs Company', 'identification_number' => '8'.random_int(10_000_000, 99_999_999)]])->assertCreated();
            $browser->json('POST', '/api/v1/auth/logout')->assertNoContent();
            $browser->json('POST', '/api/v1/auth/login', ['email' => 'synthetic-logs@example.com', 'password' => $secrets['password'].'-wrong'])->assertStatus(422);
            $browser->json('POST', '/api/v1/auth/login', ['email' => 'synthetic-logs@example.com', 'password' => $secrets['password']])->assertOk();

            // A company that issues: its software PIN and the technical key of its numbering.
            $company = $this->company('Synthetic Logs Issuer');
            $secrets['pin'] = $this->text(DianSetting::current()->software_pin);
            $secrets['technical key'] = $this->text(NumberTemplate::query()->where('document_type', NumberingType::Invoice)->firstOrFail()->technical_key);
            $api = fn () => $this->signedIn($company->user, $company);

            // A certificate, with a wrong password first.
            $secrets['certificate password'] = Str::random(24);
            $bundle = $this->pkcs12($secrets['certificate password']);
            $api()->post('/api/v1/dian-settings/certificate', ['file' => UploadedFile::fake()->createWithContent('signing.p12', $bundle), 'password' => $secrets['certificate password'].'x'])->assertStatus(422);
            $api()->post('/api/v1/dian-settings/certificate', ['file' => UploadedFile::fake()->createWithContent('signing.p12', $bundle), 'password' => $secrets['certificate password']])->assertCreated();
            // Signing goes on with a synthetic certificate valid for the simulator.
            $api()->postJson('/api/v1/dian-settings/certificate/test')->assertCreated();

            // An API key that works, and one revoked.
            $key = $this->text($api()->postJson('/api/v1/api-keys', ['name' => 'Synthetic logs key', 'level' => 'read_write'])->assertCreated()->json('data.key'));
            $revoked = $api()->postJson('/api/v1/api-keys', ['name' => 'Synthetic revoked key', 'level' => 'read_only'])->assertCreated();
            $api()->postJson('/api/v1/api-keys/'.$this->text($revoked->json('data.id')).'/revoke')->assertOk();
            $secrets['API key'] = $key;
            $secrets['revoked API key'] = $this->text($revoked->json('data.key'));

            // A webhook subscription while the receiver is down: the failure is reported with its trace.
            config(['developers.receiver.admin_url' => 'http://127.0.0.1:9']);
            app()->forgetInstance(LocalWebhookReceiver::class);
            $receiverUrl = app(LocalWebhookReceiver::class)->urlFor($company->company->id);
            $secrets['webhook secret'] = $this->text($api()->postJson('/api/v1/webhooks', ['url' => $receiverUrl, 'events' => ['invoice.issued', 'invoice.accepted']])->assertCreated()->json('data.secret'));
            config(['developers.receiver.admin_url' => config('dian.simulator.url')]);
            app()->forgetInstance(LocalWebhookReceiver::class);

            // An invoice through the chain with the API key, and its webhooks.
            Auth::forgetGuards();
            $draft = $this->text($this->withToken($key)->postJson('/api/v1/invoices', ['contact_id' => $company->customer()->id, 'lines' => [['description' => 'Synthetic logged service', 'quantity' => '1', 'unit_price' => '100000', 'tax_id' => $company->iva()]]])->assertCreated()->json('data.id'));
            Auth::forgetGuards();
            $this->withToken($key)->postJson("/api/v1/invoices/{$draft}/issue")->assertSuccessful();
            Auth::forgetGuards();
            $this->withToken($secrets['revoked API key'])->getJson('/api/v1/invoices')->assertUnauthorized();
            $this->withoutToken();
            Auth::forgetGuards();
            $this->drainOutbox($company);

            // An invitation, and a password reset.
            $api()->postJson('/api/v1/team/invitations', ['email' => 'synthetic-logs-invited@example.com', 'role' => 'viewer'])->assertCreated();
            $secrets['invitation token'] = $this->linkToken('#/invitations/([A-Za-z0-9]+)#');
            WebBrowser::at($this)->json('POST', '/api/v1/auth/forgot-password', ['email' => 'synthetic-logs@example.com'])->assertStatus(202);
            $secrets['reset token'] = $this->linkToken('#/reset-password/([A-Za-z0-9]+)#');
            $secrets['new password'] = Str::random(24);
            WebBrowser::at($this)->json('POST', '/api/v1/auth/reset-password', ['token' => $secrets['reset token'], 'email' => 'synthetic-logs@example.com', 'password' => $secrets['new password'], 'password_confirmation' => $secrets['new password']])->assertOk();

            $secrets['app key'] = config()->string('app.key');
            $secrets['redis password'] = config()->string('database.redis.default.password');
            $secrets['simulator token'] = config()->string('dian.simulator.token');

            $written = File::exists($log) ? File::get($log) : '';
        } finally {
            app('log')->forgetChannel('single');
            File::delete($log);
        }

        $this->assertNotSame('', $written, 'Nothing was logged: the reported failure of the receiver should be.');

        foreach ($secrets as $name => $secret) {
            $this->assertNotSame('', $secret, "{$name} is empty.");
            $this->assertStringNotContainsString($secret, $written, "The {$name} is in the log.");

            // What a stack trace shows of a string argument: its first 15 characters.
            $start = mb_substr(preg_replace('/^(whsec_|dei_[A-Za-z0-9]{12}_|base64:)/', '', $secret) ?? $secret, 0, 8);
            $this->assertStringNotContainsString($start, $written, "The start of the {$name} is in the log.");
        }
    }

    private function linkToken(string $pattern): string
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $message = $transport->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $message);
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);
        $this->assertSame(1, preg_match($pattern, (string) $email->getTextBody(), $match));

        return $match[1] ?? '';
    }

    /** A PKCS#12 bundle with a synthetic RSA key and a self-signed certificate. */
    private function pkcs12(string $password): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        // openssl_csr_new() takes the key by reference.
        $requestKey = $key;
        $request = openssl_csr_new(['commonName' => 'Synthetic Logs Test'], $requestKey);
        $this->assertNotFalse($request);
        $this->assertNotTrue($request);
        $certificate = openssl_csr_sign($request, null, $key, 30);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_pkcs12_export($certificate, $bundle, $key, $password));
        $this->assertIsString($bundle);

        return $bundle;
    }
}
