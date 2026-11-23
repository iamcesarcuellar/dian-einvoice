<?php

namespace App\Modules\Payments\Services;

use App\Modules\Catalog\Models\Contact;
use App\Modules\Delivery\Support\ColombianFormat;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Payments\Enums\PaymentEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\PaymentEvents;
use App\Modules\Payments\Ports\AmountExceedsBalance;
use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Ports\InvoiceNotPayable;
use App\Modules\Payments\Ports\PaymentReference;
use App\Modules\Payments\Ports\ReceiptNumbering;
use App\Support\Outbox\Outbox;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records a payment of one customer and applies it to some of its accepted invoices, in one
 * transaction. It locks the receipt numbering first (payments of a company queue there), then each
 * invoice through the ledger in the order of their ids, so two payments never take the same balance
 * twice and never deadlock. Each invoice checks that what is applied fits what is left to collect;
 * any failure rolls everything back, the receipt number included. The amount of the payment is the
 * sum applied. The same transaction writes payment.recorded, and invoice.paid for each invoice left
 * with nothing to collect.
 *
 * The validated body; decimals arrive as strings or whole numbers (see DecimalString):
 *
 * @phpstan-type PaymentInput array{contact_id: string, payment_date: string, payment_means_code: string, reference?: string|null, notes?: string|null, amount?: string|int|null, allocations: array<array{document_id: string, amount: string|int}>}
 */
final class RecordPayment
{
    /** Attempts when the database reports a deadlock. */
    private const int ATTEMPTS = 5;

    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly ReceiptNumbering $numbering,
        private readonly Outbox $outbox,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * @param  PaymentInput  $input
     */
    public function __invoke(array $input, ?User $actor = null): Payment
    {
        $allocations = $this->allocations($input);
        $total = array_reduce($allocations, fn (BigDecimal $sum, array $allocation): BigDecimal => $sum->plus($allocation['amount']), BigDecimal::zero())->toScale(2);

        if (filled($input['amount'] ?? null) && ! BigDecimal::of((string) $input['amount'])->isEqualTo($total)) {
            throw ValidationException::withMessages(['amount' => [sprintf('The payment amount must equal the sum applied to its invoices, %s.', ColombianFormat::money((string) $total))]]);
        }

        $contact = Contact::query()->findOrFail($input['contact_id']);

        return DB::transaction(function () use ($input, $allocations, $total, $contact, $actor): Payment {
            // 1. The receipt number: the numbering stays locked until the end.
            $number = $this->numbering->next();
            // Lowercase, as HasUlids writes every other id.
            $paymentId = Str::lower((string) Str::ulid());
            $reference = new PaymentReference($paymentId, $number->full(), $input['payment_date'], $input['payment_means_code']);

            // 2. The invoices, always in the order of their ids.
            $applied = [];
            $sorted = $allocations;
            uasort($sorted, fn (array $a, array $b): int => strcmp($a['document_id'], $b['document_id']));

            foreach ($sorted as $index => $allocation) {
                try {
                    $applied[$index] = $this->ledger->apply($allocation['document_id'], $contact->id, (string) $allocation['amount'], $reference);
                } catch (AmountExceedsBalance $exception) {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => [sprintf(
                        'Invoice %s has %s left to collect; %s cannot be applied to it.',
                        $exception->invoiceNumber,
                        ColombianFormat::money($exception->balance),
                        ColombianFormat::money($exception->amount),
                    )]]);
                } catch (InvoiceNotPayable $exception) {
                    throw ValidationException::withMessages(["allocations.{$index}.document_id" => [$exception->getMessage()]]);
                }
            }

            ksort($applied);

            $payment = Payment::create([
                'id' => $paymentId,
                'number_template_id' => $number->numberingId,
                'prefix' => $number->prefix,
                'number' => $number->number,
                'full_number' => $number->full(),
                'payment_date' => $input['payment_date'],
                'contact_id' => $contact->getKey(),
                'customer_name' => $contact->legal_name,
                'customer_identification' => $contact->identification_number,
                'amount' => (string) $total,
                'payment_means_code' => $input['payment_means_code'],
                'reference' => filled($input['reference'] ?? null) ? trim((string) $input['reference']) : null,
                'notes' => filled($input['notes'] ?? null) ? trim((string) $input['notes']) : null,
                'status' => PaymentStatus::Recorded,
                'created_by' => $actor?->getKey(),
            ]);

            $invoices = [];

            foreach ($applied as $index => $invoice) {
                PaymentAllocation::create([
                    'payment_id' => $paymentId,
                    'document_id' => $invoice->id,
                    'document_number' => $invoice->number,
                    'document_issue_date' => $invoice->issueDate,
                    'document_due_date' => $invoice->dueDate,
                    'amount' => (string) $allocations[$index]['amount'],
                    'balance_after' => $invoice->balance,
                ]);

                $invoices[] = ['document_id' => $invoice->id, 'number' => $invoice->number, 'amount' => (string) $allocations[$index]['amount'], 'balance' => $invoice->balance];
            }

            PaymentEvent::create([
                'payment_id' => $paymentId,
                'type' => PaymentEventType::Recorded,
                'payload' => ['number' => $number->full(), 'amount' => (string) $total, 'invoices' => $invoices],
                'occurred_at' => Carbon::now(),
                'actor_id' => $actor?->getKey(),
            ]);

            $companyId = $this->currentCompany->id();

            $this->outbox->record($companyId, 'payment', $paymentId, PaymentEvents::RECORDED, [
                'payment_id' => $paymentId,
                'number' => $number->full(),
                'payment_date' => $input['payment_date'],
                'contact_id' => $contact->getKey(),
                'amount' => (string) $total,
                'currency' => $payment->currency,
                'payment_means_code' => $input['payment_means_code'],
                'invoices' => $invoices,
            ]);

            foreach ($applied as $invoice) {
                if (! BigDecimal::of($invoice->balance)->isPositive()) {
                    $this->outbox->record($companyId, 'document', $invoice->id, PaymentEvents::INVOICE_PAID, [
                        'document_id' => $invoice->id,
                        'number' => $invoice->number,
                        'payment_id' => $paymentId,
                        'payment_number' => $number->full(),
                        'balance' => $invoice->balance,
                    ]);
                }
            }

            return $payment;
        }, self::ATTEMPTS);
    }

    /**
     * Allocations with a positive amount, keyed by their position in the request so errors point at
     * the right row.
     *
     * @param  PaymentInput  $input
     * @return array<int, array{document_id: string, amount: string}>
     */
    private function allocations(array $input): array
    {
        $allocations = [];

        foreach (array_values($input['allocations']) as $index => $allocation) {
            $amount = BigDecimal::of((string) $allocation['amount'])->toScale(2);

            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(["allocations.{$index}.amount" => ['The amount to apply must be greater than zero.']]);
            }

            $allocations[$index] = ['document_id' => (string) $allocation['document_id'], 'amount' => (string) $amount];
        }

        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => ['Apply the payment to at least one invoice.']]);
        }

        return $allocations;
    }
}
