<?php

namespace Tests\Integration\Billing;

use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\InvoiceBalance;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Services\NotePrefill;
use App\Modules\Billing\Tools\TemporaryCompany;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\IntegrationTestCase;
use Tests\Support\Parallel;

/**
 * Credit and debit notes against the balance of their invoice: crediting more than an invoice
 * is worth, or a void that leaves a balance, would be wrong money in every report.
 */
final class NotesTest extends IntegrationTestCase
{
    public function test_credit_notes_never_credit_more_than_is_left_and_the_balance_follows(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);

        $this->acceptedNote($company, DocumentType::CreditNote, $invoice, '50000');
        $this->assertBalance($invoice, BalanceStatus::PartiallyCredited, credited: '59500.00', remaining: '59500.00', receivable: '59500.00');

        // 60,000 plus IVA is 71,400: more than the 59,500 left.
        $this->assertRefused(fn () => $this->issue($company, $this->draftNote($company, DocumentType::CreditNote, $invoice, '60000')), 'lines');

        $this->acceptedNote($company, DocumentType::CreditNote, $invoice, '50000');
        $this->assertBalance($invoice, BalanceStatus::Credited, credited: '119000.00', remaining: '0.00', receivable: '0.00');
        $this->expectException(ConflictHttpException::class);
        app(NotePrefill::class)->for(DocumentType::CreditNote, $invoice->refresh());
    }

    public function test_two_simultaneous_credit_notes_cannot_both_take_what_is_left(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);
        // Each one fits alone (83,300 of 119,000); together they do not.
        $notes = [
            $this->draftNote($company, DocumentType::CreditNote, $invoice, '70000')->id,
            $this->draftNote($company, DocumentType::CreditNote, $invoice, '70000')->id,
        ];

        $outcomes = Parallel::run(2, fn (int $index): ?string => app(IssueDocument::class)($notes[$index], $company->user)->full_number);

        $this->assertSame([true, false], $this->sorted(array_column($outcomes, 'ok')));
        $this->assertContains(ValidationException::class, array_column($outcomes, 'exception'));
        $this->assertSame(1, Document::query()->whereKey($notes)->where('status', DocumentStatus::Issued)->count());
    }

    public function test_a_void_takes_all_that_is_left_and_closes_the_invoice_to_notes(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);
        $this->acceptedNote($company, DocumentType::CreditNote, $invoice, '20000');

        // A void must take the 95,200 left, not 59,500 of it.
        $this->assertRefused(fn () => $this->issue($company, $this->draftNote($company, DocumentType::CreditNote, $invoice, '50000', concept: '2')), 'reason_code');

        $this->acceptedNote($company, DocumentType::CreditNote, $invoice, '80000', concept: '2');
        $this->assertBalance($invoice, BalanceStatus::Voided, credited: '119000.00', remaining: '0.00', receivable: '0.00');

        // A voided invoice takes no more notes, not even a debit note.
        $this->assertRefused(fn () => $this->issue($company, $this->draftNote($company, DocumentType::DebitNote, $invoice->refresh(), '1000')), 'related_document_id');
        $this->expectException(ConflictHttpException::class);
        app(NotePrefill::class)->for(DocumentType::CreditNote, $invoice->refresh());
    }

    public function test_an_accepted_debit_note_raises_what_is_owed_and_what_can_be_credited(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);

        $this->acceptedNote($company, DocumentType::DebitNote, $invoice, '10000');

        $balance = app(InvoiceBalance::class)->of($invoice->refresh());
        $this->assertSame(['130900.00', '11900.00', '130900.00'], [$balance['balance'], $balance['debited'], $balance['remaining_to_credit']]);
        $this->assertSame('130900.00', (string) $invoice->receivable_amount);
    }

    private function acceptedNote(TemporaryCompany $company, DocumentType $type, Document $invoice, string $base, string $concept = '1'): Document
    {
        $note = $this->runChain($this->issue($company, $this->draftNote($company, $type, $invoice, $base, $concept)));
        $this->assertSame(ElectronicStatus::Accepted, $note->electronic_status, "{$note->full_number} was not accepted.");

        return $note;
    }

    private function assertBalance(Document $invoice, BalanceStatus $status, string $credited, string $remaining, string $receivable): void
    {
        $invoice->refresh();
        $balance = app(InvoiceBalance::class)->of($invoice);

        $this->assertSame($status, $invoice->balance_status);
        $this->assertSame([$credited, $remaining], [$balance['credited'], $balance['remaining_to_credit']]);
        $this->assertSame($receivable, (string) $invoice->receivable_amount);
    }

    private function assertRefused(callable $issue, string $field): void
    {
        try {
            $issue();
            $this->fail("The note was issued; a refusal on {$field} was expected.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    /**
     * @param  list<bool>  $values
     * @return list<bool>
     */
    private function sorted(array $values): array
    {
        rsort($values);

        return $values;
    }
}
