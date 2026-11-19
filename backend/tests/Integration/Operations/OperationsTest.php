<?php

namespace Tests\Integration\Operations;

use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Identity\Models\TeamInvitation;
use App\Support\Health\Heartbeat;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxEvent;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IntegrationTestCase;

/**
 * Operation: the pruning of old operational records with their retention (config/retention.php),
 * which never touches what is still in use, and the health checks the containers run.
 */
final class OperationsTest extends IntegrationTestCase
{
    public function test_old_operational_records_are_pruned_and_what_is_still_in_use_stays(): void
    {
        $company = $this->company();
        $companyId = $company->company->id;
        $invoice = $this->acceptedInvoice($company);
        $api = $this->signedIn($company->user, $company);

        // A webhook delivery that succeeded, an invitation that expired and one accepted, a failed job.
        $subscription = $this->text($api->postJson('/api/v1/webhooks', ['url' => app(LocalWebhookReceiver::class)->urlFor($companyId), 'events' => ['invoice.issued']])->assertCreated()->json('data.id'));
        $api->postJson("/api/v1/webhooks/{$subscription}/test")->assertCreated();
        $this->drainOutbox($company);
        $api->postJson('/api/v1/team/invitations', ['email' => 'synthetic-expired@example.com', 'role' => 'member'])->assertCreated();
        $api->postJson('/api/v1/team/invitations', ['email' => 'synthetic-accepted@example.com', 'role' => 'member'])->assertCreated();
        TeamInvitation::query()->where('email', 'synthetic-accepted@example.com')->update(['accepted_at' => Carbon::now()]);
        $unpublished = app(Outbox::class)->record($companyId, 'document', $invoice->id, 'synthetic.waiting');

        foreach (['old', 'recent'] as $age) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => "Synthetic {$age} failure", 'failed_at' => $age === 'old' ? Carbon::now()->subDays(40) : Carbon::now()]);
        }

        $responseFile = (string) $invoice->dian_response_path;
        $publishedBefore = OutboxEvent::query()->whereNotNull('published_at')->count();

        // Everything above, except the waiting event, the recent failed job and the accepted invitation, ages past its retention.
        Carbon::setTestNow(Carbon::now()->addDays(200));
        DB::table('outbox_events')->where('id', $unpublished->id)->update(['created_at' => Carbon::now()->subDays(199)]);
        DB::table('failed_jobs')->where('exception', 'Synthetic recent failure')->update(['failed_at' => Carbon::now()]);
        TeamInvitation::query()->where('email', 'synthetic-accepted@example.com')->update(['accepted_at' => Carbon::now()->subDays(199)]);

        $this->assertSame(0, Artisan::call('retention:prune'));

        $this->assertGreaterThan(0, $publishedBefore);
        $this->assertSame(0, OutboxEvent::query()->whereNotNull('published_at')->count());
        $this->assertNotNull(OutboxEvent::query()->find($unpublished->id), 'An event the relay has not published yet was pruned.');
        $this->assertSame(0, DB::table('dian_transmissions')->where('document_id', $invoice->id)->count());
        $this->assertTrue(app(DocumentStorage::class)->exists($responseFile), 'The answer the document keeps was deleted with its transmission.');
        $this->assertSame(0, DB::table('webhook_deliveries')->where('company_id', $companyId)->count());
        $this->assertSame(0, DB::table('webhook_events')->where('company_id', $companyId)->count());
        $this->assertSame(['synthetic-accepted@example.com'], TeamInvitation::query()->pluck('email')->all());
        $this->assertSame(['Synthetic recent failure'], DB::table('failed_jobs')->where('exception', 'like', 'Synthetic%')->pluck('exception')->all());
    }

    public function test_the_health_checks_of_the_api_the_workers_and_the_relay(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']]);

        $this->assertSame(0, Artisan::call('health:check'));
        $this->assertStringContainsString('healthy: database ok, redis ok', Artisan::output());

        // A worker whose loop never beat in this container is not healthy; once it beats, it is.
        $this->assertSame(1, Artisan::call('health:check', ['process' => 'worker']));
        Heartbeat::beat('worker');
        $this->assertSame(0, Artisan::call('health:check', ['process' => 'worker']));
        $this->assertSame(1, Artisan::call('health:check', ['process' => 'nothing']));
    }
}
