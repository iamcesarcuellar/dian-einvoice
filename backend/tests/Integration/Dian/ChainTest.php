<?php

namespace Tests\Integration\Dian;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Dian\Pipeline\ElectronicDocumentActions;
use App\Modules\Dian\Pipeline\StalledChainSweeper;
use App\Modules\Dian\Pipeline\TransmitDocument;
use App\Modules\Dian\Services\IssueDateRefresh;
use App\Modules\Dian\Services\SigningCertificates;
use App\Modules\Dian\Transmission\DianGateway;
use App\Modules\Dian\Transmission\TransmissionOutcome;
use App\Modules\Reference\Enums\PaymentForm;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\IntegrationTestCase;
use Tests\Support\Parallel;

/**
 * The electronic document chain against the local DIAN simulator: XML, signature, transmission
 * and answer, with the failures a real web service has. In this project accepted and rejected mean
 * by the local simulator, never by the DIAN.
 */
final class ChainTest extends IntegrationTestCase
{
    public function test_an_invoice_goes_through_the_chain_and_is_accepted(): void
    {
        $company = $this->company();

        $invoice = $this->acceptedInvoice($company);

        $this->assertSame(ElectronicStatus::Accepted, $invoice->electronic_status);
        $this->assertSame('00', $invoice->dian_status_code);
        $this->assertNotNull($invoice->xml_path);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{96}$/', (string) $invoice->cufe);
        $this->assertSame([[DianGateway::SEND_BILL_SYNC, TransmissionOutcome::Responded]], $this->calls($invoice));
        $this->assertSame(
            ['created', 'issued', 'xml_generated', 'xml_signed', 'transmission_attempt', 'accepted'],
            array_slice($this->timeline($invoice), 0, 6),
        );
    }

    public function test_a_rejection_by_a_rule_is_kept_and_resend_starts_a_new_run(): void
    {
        $company = $this->company();
        $this->simulator()->setMode($company->company->identification_number, ['mode' => 'reject', 'rule' => 'FAU02', 'count' => 1]);

        $invoice = $this->acceptedInvoice($company);

        $this->assertSame(ElectronicStatus::Rejected, $invoice->electronic_status);
        $this->assertContains('FAU02', array_column($invoice->dian_messages ?? [], 'rule'));

        $invoice = $this->runChain(app(ElectronicDocumentActions::class)->resend($invoice->id, $company->user));

        $this->assertSame(ElectronicStatus::Accepted, $invoice->electronic_status);
        $this->assertSame(2, $invoice->electronic_run);
        $this->assertSame([], array_values(array_filter($invoice->dian_messages ?? [], fn (array $message): bool => $message['kind'] === 'rejection')));
    }

    public function test_transient_failures_are_retried_until_failed_and_retry_now_finishes_the_chain(): void
    {
        $company = $this->company();
        $nit = $company->company->identification_number;
        $this->simulator()->setMode($nit, ['mode' => 'error_500']);

        $invoice = $this->acceptedInvoice($company);

        $this->assertSame(ElectronicStatus::Failed, $invoice->electronic_status);
        $this->assertSame('transmit', $invoice->electronic_failed_step);
        $attempts = DianTransmission::query()->where('document_id', $invoice->id)->where('run', 1)->get();
        $this->assertSame(range(1, 6), $attempts->pluck('attempt')->unique()->values()->all());
        $this->assertSame([TransmissionOutcome::ServerError], $attempts->pluck('outcome')->unique()->values()->all());
        $this->assertSame(1, DocumentEvent::query()->where('document_id', $invoice->id)->where('type', DocumentEventType::TransmissionFailed)->count());

        $this->simulator()->setMode($nit, ['mode' => 'normal']);
        $invoice = $this->runChain(app(ElectronicDocumentActions::class)->retryNow($invoice->id, $company->user));

        $this->assertSame(ElectronicStatus::Accepted, $invoice->electronic_status);
        $this->assertSame(2, $invoice->electronic_run);
    }

    public function test_the_dead_letter_of_a_transmission_that_gave_up_retries_it_when_an_operator_moves_it_back(): void
    {
        $company = $this->company();
        $nit = $company->company->identification_number;
        $this->simulator()->setMode($nit, ['mode' => 'error_500']);
        $invoice = $this->acceptedInvoice($company);
        $this->assertSame(ElectronicStatus::Failed, $invoice->electronic_status);
        $this->assertSame(6, $invoice->electronic_attempt);
        $this->simulator()->setMode($nit, ['mode' => 'normal']);

        // An older message of the same run, delivered late, does nothing.
        app()->call([new TransmitDocument($company->company->id, $invoice->id, 1, 2), 'handle']);
        $this->assertSame([ElectronicStatus::Failed, 1], [$invoice->refresh()->electronic_status, $invoice->electronic_run]);

        // The message of the attempt that gave up, moved back from the dead-letter queue: a new run, as Retry now does.
        app()->call([new TransmitDocument($company->company->id, $invoice->id, 1, 6), 'handle']);
        $this->assertSame([ElectronicStatus::Signed, 2], [$invoice->refresh()->electronic_status, $invoice->electronic_run]);
        $this->assertSame(1, DocumentEvent::query()->where('document_id', $invoice->id)->where('type', DocumentEventType::RetryRequested)->count());
        $invoice = $this->runChain($invoice);

        $this->assertSame(ElectronicStatus::Accepted, $invoice->electronic_status);
        $this->assertSame(1, DianTransmission::query()->where('document_id', $invoice->id)->where('run', 2)->count());

        // Moved back twice, the dead letter does nothing more: its run is over.
        app()->call([new TransmitDocument($company->company->id, $invoice->id, 1, 6), 'handle']);
        $this->assertSame([ElectronicStatus::Accepted, 2], [$invoice->refresh()->electronic_status, $invoice->electronic_run]);
    }

    public function test_a_send_that_may_have_arrived_asks_get_status_before_sending_again(): void
    {
        $company = $this->company();
        // The simulator processes the document and withholds its answer, once.
        $this->simulator()->setMode($company->company->identification_number, ['mode' => 'no_response', 'count' => 1]);
        config(['dian.timeout' => 1]);

        $invoice = $this->acceptedInvoice($company);

        $this->assertSame(ElectronicStatus::Accepted, $invoice->electronic_status);
        $this->assertSame([
            [DianGateway::SEND_BILL_SYNC, TransmissionOutcome::Timeout],
            [DianGateway::GET_STATUS, TransmissionOutcome::Found],
        ], $this->calls($invoice));
        $this->assertCount(1, $this->simulator()->documents($company->company->identification_number));
    }

    public function test_the_call_of_a_worker_that_died_is_taken_over_after_its_lease(): void
    {
        $company = $this->company();
        $this->simulator()->setMode($company->company->identification_number, ['mode' => 'no_response', 'count' => 1]);
        config(['dian.timeout' => 30]);
        $invoice = $this->issue($company, $this->draftInvoice($company));

        // A worker runs the chain and dies during its SendBillSync, once the document has arrived:
        // the simulator processed it, and the answer never came back.
        $worker = Parallel::spawn(function () use ($invoice): void {
            $this->runChain($invoice);
        });
        $deadline = microtime(true) + 20;

        while ($this->simulator()->documents($company->company->identification_number) === [] && microtime(true) < $deadline) {
            usleep(100_000);
        }

        Parallel::kill($worker);
        $this->assertTrue(DianTransmission::query()->where('document_id', $invoice->id)->whereNull('finished_at')->exists());
        $this->assertSame(ElectronicStatus::Sending, $invoice->refresh()->electronic_status);

        // Later, the relay finds the chain stalled and the call older than its lease.
        Carbon::setTestNow(Carbon::now()->addSeconds(StalledChainSweeper::STALLED_SECONDS + 10));
        $this->assertGreaterThanOrEqual(1, app(StalledChainSweeper::class)->sweep());
        $invoice = $this->runChain($invoice);

        $this->assertSame(ElectronicStatus::Accepted, $invoice->electronic_status);
        $this->assertSame([
            [DianGateway::SEND_BILL_SYNC, TransmissionOutcome::Abandoned],
            [DianGateway::GET_STATUS, TransmissionOutcome::Found],
        ], $this->calls($invoice));
        $this->assertTrue((bool) DianTransmission::query()->where('document_id', $invoice->id)->where('outcome', TransmissionOutcome::Abandoned)->value('may_have_arrived'));
    }

    public function test_a_number_the_simulator_already_accepted_for_another_document_stays_rejected_with_rule_90(): void
    {
        // Company A had this NIT and its invoice TST1 was accepted; then the NIT passes to B, which
        // numbers from 1 again, as a restored database or a second installation would.
        $first = $this->company('Synthetic First Installation');
        $nit = $first->company->identification_number;
        $this->assertSame(ElectronicStatus::Accepted, $this->acceptedInvoice($first)->electronic_status);
        $first->company->forceFill(['identification_number' => '97'.random_int(1_000_000, 9_999_999)])->save();
        $second = $this->company('Synthetic Second Installation');
        $second->company->forceFill(['identification_number' => $nit])->save();

        $invoice = $this->acceptedInvoice($second);

        // SendBillSync answers rule 90; GetStatus with this CUFE finds nothing, so the rejection stands.
        $this->assertSame('TST1', $invoice->full_number);
        $this->assertSame(ElectronicStatus::Rejected, $invoice->electronic_status);
        $this->assertContains('90', array_column($invoice->dian_messages ?? [], 'rule'));
        $this->assertSame([
            [DianGateway::SEND_BILL_SYNC, TransmissionOutcome::Responded],
            [DianGateway::GET_STATUS, TransmissionOutcome::NotFound],
        ], $this->calls($invoice));
    }

    public function test_regenerating_on_a_later_day_moves_the_dates_and_the_cufe_but_never_of_an_accepted_document(): void
    {
        $company = $this->company();
        $this->simulator()->setMode($company->company->identification_number, ['mode' => 'reject', 'rule' => 'FAU04', 'count' => 1]);
        $today = Carbon::now('America/Bogota');
        $rejected = $this->acceptedInvoice($company, header: ['payment_form' => PaymentForm::Credit->value, 'due_date' => $today->copy()->addDays(30)->toDateString()]);
        $this->assertSame(ElectronicStatus::Rejected, $rejected->electronic_status);
        $firstCufe = $rejected->cufe;

        // Resend two days later: the issue date must be the signing date (FAD09e).
        Carbon::setTestNow(Carbon::now()->addDays(2));
        $accepted = $this->runChain(app(ElectronicDocumentActions::class)->resend($rejected->id, $company->user));

        $this->assertSame(ElectronicStatus::Accepted, $accepted->electronic_status);
        $this->assertSame($today->copy()->addDays(2)->toDateString(), $accepted->issue_date?->toDateString());
        $this->assertSame($today->copy()->addDays(32)->toDateString(), $accepted->due_date?->toDateString());
        $this->assertNotSame($firstCufe, $accepted->cufe);
        $moved = DocumentEvent::query()->where('document_id', $accepted->id)->where('type', DocumentEventType::IssueDateMoved)->sole();
        $this->assertSame($firstCufe, $moved->payload['previous_key'] ?? null);
        $this->assertSame($accepted->cufe, $moved->payload['key'] ?? null);

        // An accepted document never changes: not on a later day, and not through Resend.
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $cufe = $accepted->cufe;

        try {
            app(IssueDateRefresh::class)->refresh($accepted, Carbon::now());
            $this->fail('The issue date of an accepted document moved.');
        } catch (LogicException) {
            // Expected.
        }

        $this->expectException(ConflictHttpException::class);

        try {
            app(ElectronicDocumentActions::class)->resend($accepted->id, $company->user);
        } finally {
            $unchanged = Document::query()->findOrFail($accepted->id);
            $this->assertSame($cufe, $unchanged->cufe);
            $this->assertSame($today->copy()->addDays(2)->toDateString(), $unchanged->issue_date?->toDateString());
        }
    }

    public function test_a_certificate_that_expires_between_issue_and_signing_stops_the_chain_before_anything_is_sent(): void
    {
        $company = $this->company();
        $this->actingIn($company);
        // A synthetic certificate that is valid for one more hour.
        app(SigningCertificates::class)->generateTest($company->company, Carbon::now()->subYears(2)->addHour());
        $issued = $this->issue($company, $this->draftInvoice($company));

        // The worker gets to the signature two hours later.
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $invoice = $this->runChain($issued);

        $this->assertSame(ElectronicStatus::Failed, $invoice->electronic_status);
        $this->assertSame('sign', $invoice->electronic_failed_step);
        $failure = DocumentEvent::query()->where('document_id', $invoice->id)->where('type', DocumentEventType::XmlFailed)->sole();
        $this->assertStringContainsString('expired', $this->text($failure->payload['reason'] ?? null));
        $this->assertNull($invoice->xml_signed_at);
        $this->assertSame([], $this->calls($invoice), 'An invoice signed with an expired certificate reached the DIAN.');
        $this->assertSame($issued->full_number, $invoice->full_number);
    }

    public function test_the_file_counters_start_again_on_january_1_in_colombian_time(): void
    {
        $newYear = Carbon::now('America/Bogota')->startOfYear()->addYear();
        $company = $this->company();
        $this->actingIn($company);
        // Valid from yesterday: the simulator, as the DIAN, also checks it against its own clock.
        app(SigningCertificates::class)->generateTest($company->company, Carbon::now()->subDay());
        $nit = str_pad($company->company->identification_number, 10, '0', STR_PAD_LEFT);
        $names = [];

        // Half an hour before and after midnight in Colombia: already the new year in UTC both times.
        foreach ([$newYear->copy()->subMinutes(30), $newYear->copy()->addMinutes(30), $newYear->copy()->addMinutes(40)] as $moment) {
            Carbon::setTestNow($moment);
            $invoice = $this->acceptedInvoice($company);
            $this->assertSame(ElectronicStatus::Accepted, $invoice->electronic_status);
            $names[] = [$invoice->dian_zip_file_name, $invoice->dian_xml_file_name];
        }

        $last = substr((string) ($newYear->year - 1), -2);
        $next = substr((string) $newYear->year, -2);
        $this->assertSame([
            ["z{$nit}000{$last}00000001.zip", "fv{$nit}000{$last}00000001.xml"],
            ["z{$nit}000{$next}00000001.zip", "fv{$nit}000{$next}00000001.xml"],
            ["z{$nit}000{$next}00000002.zip", "fv{$nit}000{$next}00000002.xml"],
        ], $names);
    }

    /**
     * Operation and outcome of each call to the simulator, oldest first.
     *
     * @return list<array{0: string, 1: TransmissionOutcome|null}>
     */
    private function calls(Document $document): array
    {
        return array_values(DianTransmission::query()
            ->where('document_id', $document->id)
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DianTransmission $call): array => [$call->operation, $call->outcome])
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    private function timeline(Document $document): array
    {
        return array_values(DocumentEvent::query()
            ->where('document_id', $document->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (DocumentEvent $event): string => $event->type->value)
            ->all());
    }
}
