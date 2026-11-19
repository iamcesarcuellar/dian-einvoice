<?php

namespace Tests;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Dian\Pipeline\InlineChain;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxSubscribers;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @phpstan-import-type DraftInput from DocumentDrafts
 * @phpstan-import-type DraftLine from DocumentDrafts
 *
 * Base of the integration tests: the real MySQL, Redis and local DIAN simulator of the stack. The
 * test database is recreated once per run, the Redis databases of the tests are emptied, and every
 * test works in synthetic companies of its own, so tests never share data and never touch the demo.
 * The electronic chain runs in line (InlineChain), with the real steps, outbox and simulator.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $prepared = false;

    /** @var list<TemporaryCompany> */
    private array $companies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.mysql.database');
        $this->assertIsString($database);

        if (! str_ends_with($database, '_test')) {
            throw new LogicException("The integration tests only run on a database whose name ends in _test, not {$database}.");
        }

        // Files of the tests go apart from the files of the local stack.
        config(['filesystems.disks.documents.root' => storage_path('framework/testing/documents')]);

        if (! self::$prepared) {
            if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
                throw new LogicException('The test database could not be migrated: '.Artisan::output());
            }

            Redis::connection()->flushdb();
            Redis::connection('cache')->flushdb();
            File::deleteDirectory(storage_path('framework/testing/documents'));
            self::$prepared = true;
        }
    }

    protected function tearDown(): void
    {
        // The simulator forgets the synthetic NITs of the test, with their documents and modes, and
        // its webhook receiver the companies.
        foreach ($this->companies as $company) {
            try {
                app(DianSimulator::class)->forgetTaxpayer($company->company->identification_number);
                app(LocalWebhookReceiver::class)->forget($company->company->id);
            } catch (Throwable) {
                // Best effort: a synthetic NIT or company left behind is harmless.
            }
        }

        $this->companies = [];

        parent::tearDown();
    }

    /**
     * A synthetic company ready to issue: tax profile, software, test certificate and an invoice
     * numbering. It becomes the current company, with its owner acting.
     */
    protected function company(string $name = 'Synthetic Test Company', string $prefix = 'TST'): TemporaryCompany
    {
        $company = TemporaryCompany::create($name, $prefix);
        $this->companies[] = $company;

        return $company;
    }

    /** Makes the company current again, acting as its owner (a later company() changes it). */
    protected function actingIn(TemporaryCompany $company): TemporaryCompany
    {
        $membership = Membership::query()->with('company')->where('company_id', $company->company->id)->where('role', Role::Owner)->firstOrFail();
        app(CurrentCompany::class)->set($membership);

        return $company;
    }

    /** A new user with a role in the company. */
    protected function member(TemporaryCompany $company, Role $role): User
    {
        $user = User::create([
            'name' => 'Synthetic '.$role->value,
            'email' => $role->value.'-'.Str::lower((string) Str::ulid()).'@example.com',
            'password' => 'synthetic-password',
        ]);

        Membership::create(['company_id' => $company->company->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /**
     * Signs the user in for the next requests, acting on the company, as the web app does.
     *
     * @return $this
     */
    protected function signedIn(User $user, TemporaryCompany $company): static
    {
        $this->actingAs($user, 'sanctum');

        return $this->withHeaders(['X-Company-Id' => $company->company->id, 'Accept' => 'application/json']);
    }

    /**
     * A draft invoice of the company for its final consumer, one line of 100,000 plus IVA 19 %
     * unless other lines are given.
     *
     * @param  list<DraftLine>|null  $lines
     * @param  DraftInput  $header
     */
    protected function draftInvoice(TemporaryCompany $company, ?array $lines = null, array $header = []): Document
    {
        $this->actingIn($company);

        return app(DocumentDrafts::class)->create(DocumentType::Invoice, [
            'contact_id' => $company->customer()->id,
            'lines' => $lines ?? [['description' => 'Synthetic consulting service', 'quantity' => '1', 'unit_price' => '100000', 'tax_id' => $company->iva()]],
            ...$header,
        ], $company->user);
    }

    /**
     * A draft credit or debit note of an accepted invoice: one line of $base plus the IVA the invoice
     * froze. The company gets its note numberings the first time.
     */
    protected function draftNote(TemporaryCompany $company, DocumentType $type, Document $invoice, string $base, string $concept = '1'): Document
    {
        $this->actingIn($company);

        foreach ([NumberingType::CreditNote->value => 'NC', NumberingType::DebitNote->value => 'ND'] as $numbering => $prefix) {
            NumberTemplate::query()->firstOrCreate(
                ['document_type' => $numbering, 'is_default' => true],
                ['prefix' => $prefix, 'from_number' => 1, 'to_number' => 99999999, 'next_number' => 1],
            );
        }

        return app(DocumentDrafts::class)->create($type, [
            'related_document_id' => $invoice->id,
            'reason_code' => $concept,
            'reason_text' => 'Synthetic correction of the integration tests',
            'lines' => [['description' => 'Synthetic correction', 'quantity' => '1', 'unit_price' => $base, 'tax_id' => $company->iva()]],
        ], $company->user);
    }

    /** Issues the draft as the owner of the company. */
    protected function issue(TemporaryCompany $company, Document $draft): Document
    {
        $this->actingIn($company);

        return app(IssueDocument::class)($draft->id, $company->user);
    }

    /** Runs the chain of the document in line until no event is left, and reads it again. */
    protected function runChain(Document $document): Document
    {
        app(InlineChain::class)->run($document->id);

        return $document->refresh();
    }

    /**
     * An invoice issued and run through the chain; accepted unless the simulator was told otherwise.
     *
     * @param  list<DraftLine>|null  $lines
     * @param  DraftInput  $header
     */
    protected function acceptedInvoice(TemporaryCompany $company, ?array $lines = null, array $header = []): Document
    {
        return $this->runChain($this->issue($company, $this->draftInvoice($company, $lines, $header)));
    }

    /**
     * Publishes and handles, in line, every pending outbox event of the company whose time has come,
     * oldest first, moving the clock (Carbon test time) to an event scheduled for later, as InlineChain
     * does for one document. For the events of other aggregates, such as webhook deliveries.
     */
    protected function drainOutbox(TemporaryCompany $company, int $limit = 200): void
    {
        for ($handled = 0; $handled < $limit; $handled++) {
            $event = DB::transaction(function () use ($company): ?OutboxEvent {
                $event = OutboxEvent::query()
                    ->where('company_id', $company->company->id)
                    ->whereNull('published_at')
                    ->orderByRaw('coalesce(available_at, created_at)')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $event?->forceFill(['published_at' => Carbon::now()])->save();

                return $event;
            });

            if ($event === null) {
                return;
            }

            if ($event->available_at !== null && $event->available_at->isFuture()) {
                Carbon::setTestNow($event->available_at);
            }

            foreach (app(OutboxSubscribers::class)->jobsFor($event) as $job) {
                $handle = [$job, 'handle'];

                if (! is_callable($handle)) {
                    throw new LogicException($job::class.' has no handle() method.');
                }

                app()->call($handle);
            }
        }

        throw new LogicException("The outbox of {$company->company->name} did not empty after {$limit} events.");
    }

    /** A string read from a JSON answer, failing the test when it is anything else. */
    protected function text(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    /** The admin API of the local DIAN simulator. */
    protected function simulator(): DianSimulator
    {
        return app(DianSimulator::class);
    }
}
