<?php

namespace Tests\Integration\Operations;

use App\Modules\Developers\Models\WebhookSubscription;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\RelayHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\IntegrationTestCase;
use Tests\Support\SecretShapes;

/**
 * The operation commands the runbooks name (docs/runbooks/), on the data of a synthetic company: what each one tells an
 * operator, read only, and the check of the migrations a deployment runs before the functions take the new code.
 */
final class OperationCommandsTest extends IntegrationTestCase
{
    public function test_the_outbox_status_tells_what_waits_how_old_it_is_and_when_the_relay_last_ran(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);
        $event = app(Outbox::class)->record($company->company->id, 'document', $invoice->id, 'synthetic.waiting');
        DB::table('outbox_events')->where('id', $event->id)->update(['created_at' => Carbon::now()->subMinutes(5)]);

        $this->assertSame(0, Artisan::call('outbox:status'));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/unpublished: \d+ events, the oldest (29\d|30\d) s old/', $output);
        $this->assertStringContainsString('synthetic.waiting', $output);
        $this->assertStringContainsString('verdict: the relay does not run', $output);

        RelayHeartbeat::beat();
        $this->assertSame(0, Artisan::call('outbox:status'));
        $this->assertStringContainsString('verdict: events wait longer than the relay should allow', Artisan::output());
        DB::table('outbox_events')->where('id', $event->id)->delete();
    }

    public function test_the_chain_status_tells_what_failed_and_how_the_calls_to_the_dian_ended(): void
    {
        $company = $this->company();
        $this->simulator()->setMode($company->company->identification_number, ['mode' => 'error_500']);
        $invoice = $this->acceptedInvoice($company);

        $this->assertSame(0, Artisan::call('chain:status', ['--company' => $company->company->id]));
        $output = Artisan::output();
        $this->assertStringContainsString('failed in the last 24 hours: 1 at transmit', $output);
        $this->assertStringContainsString('Attempt 6 of 6', $output);
        $this->assertMatchesRegularExpression('/the DIAN in the last 15 minutes: 6 calls, 6 without a validation result \(100%\): server_error 6/', $output);

        $this->assertSame(0, Artisan::call('chain:status', ['--document' => $invoice->id]));
        $output = Artisan::output();
        $this->assertStringContainsString('electronic: failed since', $output);
        $this->assertStringContainsString('failed at transmit', $output);
        $this->assertSame(6, substr_count($output, 'SendBillSync  server_error HTTP 500'));
        $this->assertSame(1, Artisan::call('chain:status', ['--document' => '01jsyntheticnodocument0000']));
    }

    public function test_the_webhooks_status_names_the_failing_subscriptions_by_the_host_of_their_url_only(): void
    {
        $company = $this->company();
        $subscription = WebhookSubscription::create(['url' => 'https://hooks.synthetic-customer.example/endpoint/secret-path-token', 'events' => ['invoice.accepted'], 'is_active' => true, 'secret' => SecretShapes::webhookSecret()]);
        $subscription->forceFill(['consecutive_failures' => 7, 'last_response_code' => 503, 'last_delivery_at' => Carbon::now()])->save();

        $this->assertSame(0, Artisan::call('webhooks:status'));
        $output = Artisan::output();
        $this->assertStringContainsString("{$subscription->id} of the company {$company->company->id}, to hooks.synthetic-customer.example: active, 7 failures in a row", $output);
        $this->assertStringContainsString('the last answer HTTP 503', $output);
        $this->assertStringNotContainsString('secret-path-token', $output);
    }

    public function test_the_connections_of_the_database_by_user(): void
    {
        $this->assertSame(0, Artisan::call('db:connections'));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/max_connections \d+; open now \d+; the most open at once since the server started \d+/', $output);
        $this->assertStringContainsString('The functions may hold 60 at once', $output);
    }

    public function test_the_probe_of_the_dian_tells_whether_its_web_service_answers(): void
    {
        $this->assertSame(0, Artisan::call('dian:probe'));
        $this->assertStringContainsString('answered GetStatus', Artisan::output());

        $this->simulator()->setMode('*', ['mode' => 'error_500']);

        try {
            $this->assertSame(1, Artisan::call('dian:probe'));
            $this->assertStringContainsString('did not answer GetStatus', Artisan::output());
        } finally {
            $this->simulator()->setMode('*', ['mode' => 'normal']);
        }
    }

    public function test_every_migration_of_the_application_is_safe_to_run_before_the_functions_take_the_new_code(): void
    {
        $exit = Artisan::call('migrations:check');
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertMatchesRegularExpression('/The \d+ migrations only add to the schema/', $output);
    }
}
