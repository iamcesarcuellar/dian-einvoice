<?php

namespace Tests\Integration\Workspace;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Payments\Services\RecordPayment;
use App\Modules\Reference\Enums\PaymentForm;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Tests\IntegrationTestCase;

/**
 * The dashboard sums what is receivable in SQL; the Receivables report walks the open invoices in
 * PHP. Two people looking at the two screens must read the same figures.
 */
final class FiguresTest extends IntegrationTestCase
{
    private const array OVERDUE_BUCKETS = ['days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    public function test_the_dashboard_and_receivables_give_the_same_totals(): void
    {
        $company = $this->company();
        $today = Carbon::now('America/Bogota');
        $credit = fn (int $days): array => ['payment_form' => PaymentForm::Credit->value, 'due_date' => $today->copy()->addDays($days)->toDateString()];

        $soon = $this->acceptedInvoice($company, header: $credit(30));
        $later = $this->acceptedInvoice($company, header: [...$credit(60), 'withholding_ids' => [$this->text(Withholding::query()->value('id'))]]);
        $cash = $this->acceptedInvoice($company);
        $paid = $this->acceptedInvoice($company, header: $credit(5));
        $credited = $this->acceptedInvoice($company, header: $credit(20));
        $this->pay($company, $soon, '19000.00');
        $this->pay($company, $paid, (string) $paid->refresh()->receivable_amount);
        $this->runChain($this->issue($company, $this->draftNote($company, DocumentType::CreditNote, $credited, '40000')));

        // Forty days later: one invoice is not due yet, the others are 10, 20 and 40 days past due.
        Carbon::setTestNow(Carbon::now()->addDays(40));
        $api = $this->signedIn($company->user, $company);
        $dashboard = $api->getJson('/api/v1/dashboard')->assertOk();
        $receivables = $api->getJson('/api/v1/receivables')->assertOk();
        $total = $this->text($receivables->json('data.totals.total'));

        $stored = Document::query()->whereKey([$soon->id, $later->id, $cash->id, $credited->id])->get()
            ->reduce(fn (BigDecimal $sum, Document $invoice): BigDecimal => $sum->plus((string) $invoice->receivable_amount), BigDecimal::zero());
        $overdue = array_reduce(self::OVERDUE_BUCKETS, fn (BigDecimal $sum, string $bucket): BigDecimal => $sum->plus($this->text($receivables->json("data.totals.{$bucket}"))), BigDecimal::zero());

        $this->assertSame((string) $stored->toScale(2), $total);
        $this->assertSame([$total, 4], [$dashboard->json('data.kpis.receivable.amount'), $dashboard->json('data.kpis.receivable.count')]);
        $this->assertSame([(string) $overdue->toScale(2), 3], [$dashboard->json('data.kpis.overdue.amount'), $dashboard->json('data.kpis.overdue.count')]);
        $this->assertSame(3, $receivables->json('data.totals.overdue_invoices'));
        $this->assertSame($total, $dashboard->json('data.aging.total'));

        foreach (['current', ...self::OVERDUE_BUCKETS] as $bucket) {
            $this->assertSame($receivables->json("data.totals.{$bucket}"), $dashboard->json("data.aging.{$bucket}"), "Bucket {$bucket} differs.");
        }

        $this->assertTrue(BigDecimal::of($this->text($receivables->json('data.totals.current')))->isEqualTo((string) $later->refresh()->receivable_amount));
        $this->assertFalse(BigDecimal::of($this->text($receivables->json('data.totals.days_31_60')))->isZero());
    }

    private function pay(TemporaryCompany $company, Document $invoice, string $amount): void
    {
        $this->actingIn($company);

        app(RecordPayment::class)([
            'contact_id' => $this->text($invoice->contact_id),
            'payment_date' => Carbon::now('America/Bogota')->toDateString(),
            'payment_means_code' => '10',
            'allocations' => [['document_id' => $invoice->id, 'amount' => $amount]],
        ], $company->user);
    }
}
