<?php

namespace App\Modules\Payments\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Payments\Enums\PaymentEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\PaymentEvents;
use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Ports\PaymentReference;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Voids a recorded payment, with its reason, in one transaction: the payment row first, then each
 * of its invoices through the ledger in the order of their ids, which get back what the payment
 * applied. A payment is never edited or deleted; the same transaction writes payment.voided.
 */
final class VoidPayment
{
    private const int ATTEMPTS = 5;

    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly Outbox $outbox,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function __invoke(string $paymentId, string $reason, ?User $actor = null): Payment
    {
        return DB::transaction(function () use ($paymentId, $reason, $actor): Payment {
            $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->isVoided()) {
                throw new ConflictHttpException("Payment {$payment->full_number} is already voided.");
            }

            $reference = new PaymentReference($payment->getKey(), $payment->full_number, $payment->payment_date->toDateString(), $payment->payment_means_code->value);
            $invoices = [];

            foreach ($payment->allocations()->get()->sortBy('document_id') as $allocation) {
                $invoice = $this->ledger->release($allocation->document_id, $reference);
                $invoices[] = ['document_id' => $invoice->id, 'number' => $invoice->number, 'amount' => (string) $allocation->amount, 'balance' => $invoice->balance];
            }

            $now = Carbon::now();

            $payment->fill([
                'status' => PaymentStatus::Voided,
                'void_reason' => trim($reason),
                'voided_at' => $now,
                'voided_by' => $actor?->getKey(),
            ])->save();

            PaymentEvent::create([
                'payment_id' => $payment->getKey(),
                'type' => PaymentEventType::Voided,
                'payload' => ['reason' => trim($reason), 'invoices' => $invoices],
                'occurred_at' => $now,
                'actor_id' => $actor?->getKey(),
            ]);

            $this->outbox->record($this->currentCompany->id(), 'payment', $payment->getKey(), PaymentEvents::VOIDED, [
                'payment_id' => $payment->getKey(),
                'number' => $payment->full_number,
                'amount' => (string) $payment->amount,
                'reason' => trim($reason),
                'invoices' => $invoices,
            ]);

            return $payment;
        }, self::ATTEMPTS);
    }
}
