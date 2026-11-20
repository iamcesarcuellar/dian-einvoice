<?php

namespace Tests\Integration\Operations;

use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxPause;
use App\Support\Outbox\OutboxPublisher;
use App\Support\Outbox\OutboxRelay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\IntegrationTestCase;

/**
 * The outbox of the functions, where no relay runs all the time: the fast path, by which a writer
 * publishes its own events right after its transaction commits and before it answers; and the safety net,
 * the relay function, which once a minute publishes what was left behind, older than a few seconds. Both
 * claim rows with FOR UPDATE SKIP LOCKED, so neither publishes a row the other holds.
 */
final class OutboxFastPathTest extends IntegrationTestCase
{
    /** @var list<list<string>> The ids of each publish call. */
    private array $published = [];

    private bool $failing = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OutboxPublisher::class, new class($this) implements OutboxPublisher
        {
            public function __construct(private readonly OutboxFastPathTest $test) {}

            public function publish(array $events): void
            {
                $this->test->published(array_values(array_map(fn (OutboxEvent $event): string => $event->id, $events)));
            }
        });

        config(['outbox.publish_after_commit' => true, 'outbox.pending_after_seconds' => 10]);
    }

    /**
     * @param  list<string>  $ids
     */
    public function published(array $ids): void
    {
        if ($this->failing) {
            throw new RuntimeException('The topic is not answering (synthetic).');
        }

        $this->published[] = $ids;
    }

    public function test_the_writer_publishes_the_events_of_its_transaction_after_the_commit_together(): void
    {
        $company = $this->company();
        $this->published = [];

        $ids = DB::transaction(function () use ($company): array {
            $first = $this->record($company->company->id, 'synthetic.first');
            $second = $this->record($company->company->id, 'synthetic.second');

            // Nothing goes out before the commit: the change could still roll back.
            $this->assertSame([], $this->published);

            return [$first->id, $second->id];
        });

        $this->assertSame([$ids], $this->published, 'One call after the commit, with both events in order.');
        $this->assertSame(0, OutboxEvent::query()->whereKey($ids)->whereNull('published_at')->count());
    }

    public function test_a_transaction_that_rolls_back_publishes_nothing(): void
    {
        $company = $this->company();
        $this->published = [];

        try {
            DB::transaction(function () use ($company): void {
                $this->record($company->company->id, 'synthetic.rolled_back');

                throw new RuntimeException('The change fails (synthetic).');
            });
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame([], $this->published);
        $this->assertSame(0, OutboxEvent::query()->where('type', 'synthetic.rolled_back')->count());

        // The next transaction publishes its own event, and the id that rolled back is claimed for nothing.
        $kept = DB::transaction(fn (): OutboxEvent => $this->record($company->company->id, 'synthetic.kept'));
        $this->assertSame([[$kept->id]], $this->published);
    }

    public function test_a_publish_that_fails_loses_nothing_and_the_safety_net_publishes_it_later(): void
    {
        $company = $this->company();
        $this->failing = true;

        // The change is committed and the request goes on; the event waits in the outbox.
        $event = DB::transaction(fn (): OutboxEvent => $this->record($company->company->id, 'synthetic.left_behind'));
        $this->assertNull($event->fresh()?->published_at);

        $this->failing = false;
        $this->published = [];

        // The relay function leaves the fresh events to their writers...
        $this->assertSame(0, Artisan::call('outbox:publish-pending'));
        $this->assertNotContains($event->id, array_merge([], ...$this->published));
        $this->assertNull($event->fresh()?->published_at);

        // ...and publishes those older than outbox.pending_after_seconds.
        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->assertSame(0, Artisan::call('outbox:publish-pending'));
        $this->assertContains($event->id, array_merge([], ...$this->published));
        $this->assertNotNull($event->fresh()?->published_at);
        $this->assertStringContainsString('older than 10 s', Artisan::output());
    }

    public function test_a_row_already_published_or_claimed_by_another_relay_is_not_published_again(): void
    {
        $company = $this->company();
        $event = DB::transaction(fn (): OutboxEvent => $this->record($company->company->id, 'synthetic.once'));
        $this->published = [];

        // Published by the fast path: neither path takes it again.
        $this->assertSame(0, app(OutboxRelay::class)->publishEvents([$event->id]));
        Carbon::setTestNow(Carbon::now()->addMinute());
        app(OutboxRelay::class)->publishBatch(olderThan: 10);
        $this->assertNotContains($event->id, array_merge([], ...$this->published));

        // A row another relay holds (FOR UPDATE on a second connection) is skipped, not waited for.
        config(['outbox.publish_after_commit' => false]);
        $held = $this->record($company->company->id, 'synthetic.held');
        config(['database.connections.mysql_other' => config('database.connections.mysql')]);
        $other = DB::connection('mysql_other');
        $other->beginTransaction();

        try {
            $other->select('select id from outbox_events where id = ? for update', [$held->id]);
            $this->assertSame(0, app(OutboxRelay::class)->publishEvents([$held->id]));
        } finally {
            $other->rollBack();
            DB::purge('mysql_other');
        }

        $this->assertSame(1, app(OutboxRelay::class)->publishEvents([$held->id]));
    }

    public function test_the_relay_function_and_the_sweepers_wait_while_the_demo_seeder_holds_the_outbox(): void
    {
        $company = $this->company();
        config(['outbox.publish_after_commit' => false]);
        $event = $this->record($company->company->id, 'synthetic.paused');
        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->published = [];

        app(OutboxPause::class)->pause(60);

        try {
            $this->assertSame(0, Artisan::call('outbox:publish-pending'));
            $this->assertStringContainsString('paused', Artisan::output());
            $this->assertSame(0, Artisan::call('outbox:sweep'));
            $this->assertStringContainsString('paused', Artisan::output());
            $this->assertSame([], $this->published);
        } finally {
            app(OutboxPause::class)->resume();
        }

        $this->assertSame(0, Artisan::call('outbox:publish-pending'));
        $this->assertNotNull($event->fresh()?->published_at);
    }

    public function test_locally_the_writer_publishes_nothing_and_leaves_it_to_the_relay_process(): void
    {
        $company = $this->company();
        config(['outbox.publish_after_commit' => false]);
        $this->published = [];

        $event = DB::transaction(fn (): OutboxEvent => $this->record($company->company->id, 'synthetic.local'));

        $this->assertSame([], $this->published);
        $this->assertNull($event->fresh()?->published_at);
    }

    private function record(string $companyId, string $type): OutboxEvent
    {
        return app(Outbox::class)->record($companyId, 'document', $companyId, $type);
    }
}
