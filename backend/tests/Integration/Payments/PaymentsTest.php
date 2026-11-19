<?php

namespace Tests\Integration\Payments;

use App\Modules\Billing\Enums\InvoicePaymentStatus;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Payments\Services\RecordPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\IntegrationTestCase;
use Tests\Support\Parallel;

/**
 * Payments from customers applied to their accepted invoices: what is collected, and what is
 * still owed, must never be more or less than the money that came in.
 */
final class PaymentsTest extends IntegrationTestCase
{
    public function test_a_payment_split_across_invoices_and_its_void_move_the_balances(): void
    {
        $company = $this->company();
        $first = $this->acceptedInvoice($company);
        $second = $this->acceptedInvoice($company);

        $payment = $this->pay($company, '150000.00', [$first->id => '119000.00', $second->id => '31000.00'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '150000.00')
            ->assertJsonPath('data.allocations.1.balance_after', '88000.00');

        $this->assertOwed($first, '0.00', InvoicePaymentStatus::Paid);
        $this->assertOwed($second, '88000.00', InvoicePaymentStatus::PartiallyPaid);

        $api = $this->signedIn($company->user, $company);
        $id = $this->text($payment->json('data.id'));
        // A lowercase ULID, like every other id of the API.
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', $id);
        $api->postJson("/api/v1/payments/{$id}/void", ['reason' => 'Synthetic payment recorded twice by mistake'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');
        $api->postJson("/api/v1/payments/{$id}/void", ['reason' => 'Synthetic second void of the same payment'])->assertStatus(409);

        $this->assertOwed($first, '119000.00', InvoicePaymentStatus::Unpaid);
        $this->assertOwed($second, '119000.00', InvoicePaymentStatus::Unpaid);
    }

    public function test_a_payment_above_the_balance_is_refused_and_changes_nothing(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);

        $this->pay($company, '119000.01', [$invoice->id => '119000.01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allocations.0.amount']);

        $this->assertOwed($invoice, '119000.00', InvoicePaymentStatus::Unpaid);
    }

    public function test_simultaneous_payments_never_collect_more_than_the_balance(): void
    {
        $company = $this->company();
        $invoice = $this->acceptedInvoice($company);
        $input = [
            'contact_id' => $this->text($invoice->contact_id),
            'payment_date' => Carbon::now('America/Bogota')->toDateString(),
            'payment_means_code' => '47',
            'amount' => '29750.00',
            'allocations' => [['document_id' => $invoice->id, 'amount' => '29750.00']],
        ];

        // Eight quarters of the balance at the same moment: exactly four fit.
        $outcomes = Parallel::run(8, fn (): string => app(RecordPayment::class)($input, $company->user)->full_number);

        $this->assertSame(4, count(array_filter(array_column($outcomes, 'ok'))));
        $this->assertSame([ValidationException::class], array_values(array_unique(array_filter(array_column($outcomes, 'exception')))));
        $this->assertOwed($invoice, '0.00', InvoicePaymentStatus::Paid);

        $receipts = NumberTemplate::query()->where('document_type', NumberingType::Payment)->where('is_default', true)->firstOrFail();
        $numbers = array_map(fn (string $number): int => (int) preg_replace('/\D/', '', $number), array_values(array_filter(array_column($outcomes, 'value'))));
        sort($numbers);
        $this->assertSame(range($receipts->from_number, $receipts->from_number + 3), $numbers);
    }

    /**
     * @param  array<string, string>  $allocations  Amount by invoice id.
     * @return TestResponse<JsonResponse>
     */
    private function pay(TemporaryCompany $company, string $amount, array $allocations): TestResponse
    {
        return $this->signedIn($company->user, $company)->postJson('/api/v1/payments', [
            'contact_id' => $company->customer()->id,
            'payment_date' => Carbon::now('America/Bogota')->toDateString(),
            'payment_means_code' => '10',
            'reference' => 'Synthetic transfer',
            'amount' => $amount,
            'allocations' => array_map(fn (string $id, string $value): array => ['document_id' => $id, 'amount' => $value], array_keys($allocations), $allocations),
        ]);
    }

    private function assertOwed(Document $invoice, string $receivable, InvoicePaymentStatus $status): void
    {
        $invoice = Document::query()->findOrFail($invoice->id);

        $this->assertSame($receivable, (string) $invoice->receivable_amount, "Receivable of {$invoice->full_number}.");
        $this->assertSame($status, $invoice->payment_status, "Payment status of {$invoice->full_number}.");
    }
}
