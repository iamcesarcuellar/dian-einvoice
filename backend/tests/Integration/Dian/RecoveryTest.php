<?php

namespace Tests\Integration\Dian;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Delivery\Pipeline\SendDeliveryEmail;
use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Receiver\LocalWebhookReceiver;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Dian\Pipeline\ChainEvents;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxSubscribers;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\IntegrationTestCase;
use Tests\Support\ScriptedMailTransport;

/**
 * The two ways back of an invoice whose transmission gave up: Retry now, which the company presses, and its dead letter
 * moved back to the queue, which an operator does (docs/runbooks/dead-letters.md). Whichever comes first recovers the
 * invoice; the other, after it, does nothing: no second call to the DIAN, no second email, no second webhook, and not a
 * column of the invoice changes. The same holds for a delivery whose email gave up, between Send again and its dead
 * letter. The dead letter is the message of the attempt that gave up, the job of its own outbox event, built as the
 * consumer builds it from the message.
 */
final class RecoveryTest extends IntegrationTestCase
{
    public function test_retry_now_first_recovers_the_invoice_and_its_dead_letter_moved_back_afterwards_does_nothing(): void
    {
        [$company, $invoice, $gaveUp] = $this->transmissionThatGaveUp();

        // The company presses Retry now: a new run, accepted and delivered.
        $this->signedIn($company->user, $company)->postJson("/api/v1/invoices/{$invoice->id}/retry")->assertOk();
        $this->drainOutbox($company);
        $recovered = $this->footprint($company, $invoice);
        $this->assertRecoveredOnce($invoice, $gaveUp, $recovered);

        // Later the operator moves the dead letters back: the message of the attempt that gave up, in run 1.
        $this->moveBack($company, $this->deadLetterOf($invoice));
        $this->drainOutbox($company);

        $this->assertSame($recovered, $this->footprint($company, $invoice), 'The dead letter of a run already retried did something.');
    }

    public function test_the_dead_letter_moved_back_first_recovers_the_invoice_and_retry_now_afterwards_is_refused(): void
    {
        [$company, $invoice, $gaveUp] = $this->transmissionThatGaveUp();

        // The operator moves the dead letter back: the step starts again, as Retry now does, and the invoice is accepted and delivered.
        $this->moveBack($company, $this->deadLetterOf($invoice));
        $this->drainOutbox($company);
        $recovered = $this->footprint($company, $invoice);
        $this->assertRecoveredOnce($invoice, $gaveUp, $recovered);

        // Then the company, who saw it failed, presses Retry now: refused, the invoice is accepted.
        $this->signedIn($company->user, $company)->postJson("/api/v1/invoices/{$invoice->id}/retry")->assertConflict();
        $this->drainOutbox($company);

        $this->assertSame($recovered, $this->footprint($company, $invoice), 'Retry now on an invoice already recovered did something.');
    }

    public function test_send_again_first_delivers_the_invoice_and_the_dead_letter_of_its_email_moved_back_afterwards_does_nothing(): void
    {
        Mail::extend('scripted', fn (): ScriptedMailTransport => new ScriptedMailTransport);
        config(['mail.mailers.scripted' => ['transport' => 'scripted'], 'mail.default' => 'scripted']);
        ScriptedMailTransport::$sent = [];
        $company = $this->company();
        $this->subscribe($company);

        // The mailbox of the customer refuses the email (550): final, the delivery fails at the email, run 1.
        ScriptedMailTransport::$script = [550];
        $invoice = $this->issue($company, $this->draftInvoice($company, header: ['contact_id' => $this->customerWithEmail($company)]));
        $this->drainOutbox($company);
        $this->assertSame([DeliveryStatus::Failed, 1], [$invoice->refresh()->delivery_status, $invoice->delivery_run]);

        // The company presses Send again once the mailbox exists: run 2, delivered by one email.
        $this->signedIn($company->user, $company)->postJson("/api/v1/invoices/{$invoice->id}/deliver")->assertSuccessful();
        $this->drainOutbox($company);
        $delivered = $this->footprint($company, $invoice);
        $this->assertSame([DeliveryStatus::Delivered, 2], [$invoice->refresh()->delivery_status, $invoice->delivery_run]);
        $this->assertSame([EmailOutcome::Rejected, EmailOutcome::Sent], DeliveryAttempt::query()->where('document_id', $invoice->id)->orderBy('attempt')->pluck('outcome')->all());
        $this->assertSame(1, $delivered['emails']);
        $this->assertSame(['invoice.issued' => 1, 'invoice.accepted' => 1, 'invoice.delivered' => 1], $delivered['webhooks']);

        // Later the operator moves the dead letters back: the email of run 1, which gave up.
        app()->call([new SendDeliveryEmail($company->company->id, $invoice->id, 1, 1), 'handle']);
        $this->drainOutbox($company);

        $this->assertSame($delivered, $this->footprint($company, $invoice), 'The dead letter of a delivery already sent again did something.');
    }

    /**
     * A company with a customer that has an email and a webhook subscription, and an invoice of it whose transmission gave
     * up: the DIAN answered HTTP 500 to each of its attempts. The DIAN answers again afterwards.
     *
     * @return array{0: TemporaryCompany, 1: Document, 2: array<string, mixed>} The company, the invoice and its footprint.
     */
    private function transmissionThatGaveUp(): array
    {
        $company = $this->company();
        $this->subscribe($company);
        $nit = $company->company->identification_number;
        $this->simulator()->setMode($nit, ['mode' => 'error_500']);

        $invoice = $this->issue($company, $this->draftInvoice($company, header: ['contact_id' => $this->customerWithEmail($company)]));
        $this->drainOutbox($company);
        $this->actingIn($company);
        $invoice->refresh();

        $this->assertSame([ElectronicStatus::Failed, 'transmit', 1, 6], [$invoice->electronic_status, $invoice->electronic_failed_step, $invoice->electronic_run, $invoice->electronic_attempt]);
        $gaveUp = $this->footprint($company, $invoice);
        $this->assertSame(['invoice.issued' => 1, 'invoice.failed' => 1], $gaveUp['webhooks']);
        $this->assertSame([0, 0, 0], [$gaveUp['dian_documents'], $gaveUp['email_attempts'], $gaveUp['emails']]);
        $this->simulator()->setMode($nit, ['mode' => 'normal']);

        return [$company, $invoice, $gaveUp];
    }

    /** The outbox event of the attempt that gave up, whose message the dead-letter queue keeps. */
    private function deadLetterOf(Document $invoice): OutboxEvent
    {
        $event = OutboxEvent::query()
            ->where('aggregate_id', $invoice->id)
            ->where('type', ChainEvents::TRANSMIT)
            ->get()
            ->first(fn (OutboxEvent $event): bool => $event->payloadInt('run') === $invoice->electronic_run && $event->payloadInt('attempt') === $invoice->electronic_attempt);
        $this->assertInstanceOf(OutboxEvent::class, $event, 'The outbox event of the attempt that gave up is not there.');

        return $event;
    }

    /** The message of an event moved back from the dead-letter queue: its job, built from it and run as the consumer runs it. */
    private function moveBack(TemporaryCompany $company, OutboxEvent $event): void
    {
        $jobs = app(OutboxSubscribers::class)->jobsFor($event, OutboxSubscribers::CHAIN);
        $this->assertCount(1, $jobs);
        $handle = [$jobs[0], 'handle'];

        if (! is_callable($handle)) {
            throw new LogicException($jobs[0]::class.' has no handle() method.');
        }

        app()->call($handle);
        $this->actingIn($company);
    }

    /**
     * Everything the platform did for the invoice: the invoice itself, every column; its timeline; the calls to the DIAN,
     * as the platform recorded them and as the simulator received them; the emails to its customer; the webhooks of the
     * company, with their attempts; and the outbox events of the company.
     *
     * @return array<string, mixed>
     */
    private function footprint(TemporaryCompany $company, Document $invoice): array
    {
        $this->actingIn($company);
        $nit = $company->company->identification_number;

        return [
            'invoice' => Document::query()->findOrFail($invoice->id)->getAttributes(),
            'timeline' => DocumentEvent::query()->where('document_id', $invoice->id)->count(),
            'dian_calls' => DianTransmission::query()->where('document_id', $invoice->id)->count(),
            'dian_sends_received' => count(array_filter($this->simulator()->requests($nit, 200), fn (array $request): bool => ($request['operation'] ?? null) === 'SendBillSync')),
            'dian_documents' => count($this->simulator()->documents($nit)),
            'email_attempts' => DeliveryAttempt::query()->where('document_id', $invoice->id)->count(),
            'emails' => $this->emails(),
            'webhooks' => $this->webhooks($company),
            'webhook_attempts' => WebhookAttempt::query()->whereIn('delivery_id', WebhookDelivery::query()->whereIn('subscription_id', WebhookSubscription::query()->select('id'))->select('id'))->count(),
            'outbox' => OutboxEvent::query()->where('company_id', $company->company->id)->count(),
        ];
    }

    /**
     * Recovered once: accepted in run 2 after one more send to the DIAN, delivered by one email, one webhook of each event.
     *
     * @param  array<string, mixed>  $gaveUp
     * @param  array<string, mixed>  $recovered
     */
    private function assertRecoveredOnce(Document $invoice, array $gaveUp, array $recovered): void
    {
        $invoice->refresh();
        $this->assertSame([ElectronicStatus::Accepted, 2, DeliveryStatus::Delivered], [$invoice->electronic_status, $invoice->electronic_run, $invoice->delivery_status]);
        $this->assertSame(1, DianTransmission::query()->where('document_id', $invoice->id)->where('run', 2)->count(), 'One call to the DIAN in the run of the recovery.');
        $this->assertSame([$gaveUp['dian_calls'], $gaveUp['dian_sends_received']], [$this->less($recovered['dian_calls']), $this->less($recovered['dian_sends_received'])], 'One more send to the DIAN, as the platform recorded it and as the simulator received it.');
        $this->assertSame(1, $recovered['dian_documents'], 'The simulator accepted the invoice once.');
        $this->assertSame([1, 1], [$recovered['email_attempts'], $recovered['emails']]);
        $this->assertSame(['invoice.issued' => 1, 'invoice.failed' => 1, 'invoice.accepted' => 1, 'invoice.delivered' => 1], $recovered['webhooks']);
    }

    /** One less than a count. */
    private function less(mixed $count): int
    {
        $this->assertIsInt($count);

        return $count - 1;
    }

    /** The emails the array mailer of the tests took. */
    private function emails(): int
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();

        return $transport instanceof ArrayTransport ? $transport->messages()->count() : count(ScriptedMailTransport::$sent);
    }

    /**
     * The webhook deliveries of the company, by event type; each one succeeded.
     *
     * @return array<string, int>
     */
    private function webhooks(TemporaryCompany $company): array
    {
        $this->actingIn($company);
        $deliveries = WebhookDelivery::query()->whereIn('subscription_id', WebhookSubscription::query()->select('id'))->orderBy('created_at')->orderBy('id')->get();
        $counts = [];

        foreach ($deliveries as $delivery) {
            $counts[$delivery->event_type] = ($counts[$delivery->event_type] ?? 0) + 1;
        }

        return $counts;
    }

    /** A subscription of the company, through the API, to its own local receiver, for the events of an invoice. */
    private function subscribe(TemporaryCompany $company): void
    {
        $this->signedIn($company->user, $company)->postJson('/api/v1/webhooks', [
            'url' => app(LocalWebhookReceiver::class)->urlFor($company->company->id),
            'events' => ['invoice.issued', 'invoice.accepted', 'invoice.failed', 'invoice.delivered'],
            'description' => 'Synthetic subscription of the integration tests',
        ])->assertCreated();
    }

    /** A customer of the company with an email, through the API. */
    private function customerWithEmail(TemporaryCompany $company): string
    {
        return $this->text($this->signedIn($company->user, $company)->postJson('/api/v1/contacts', [
            'organization_type' => '1',
            'identification_type' => '31',
            'identification_number' => '900123456',
            'legal_name' => 'Synthetic Customer SAS',
            'tax_scheme' => '01',
            'fiscal_responsibilities' => ['O-48'],
            'email' => 'synthetic-customer@example.com',
            'municipality_code' => '05001',
        ])->assertCreated()->json('data.id'));
    }
}
