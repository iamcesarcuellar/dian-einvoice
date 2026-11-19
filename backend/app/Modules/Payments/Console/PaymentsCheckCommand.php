<?php

namespace App\Modules\Payments\Console;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Services\RecordPayment;
use App\Support\Console\LocalOnly;
use Brick\Math\BigDecimal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Development check of concurrent payments, on a temporary company of its own that is deleted at
 * the end: an invoice goes through the real chain (the queue workers) until the local DIAN
 * simulator accepts it, then
 * 20 processes record at the same moment a payment of a tenth of its balance each. Exactly 10 must
 * be recorded and 10 rejected for exceeding the balance; the invoice must end with nothing to
 * collect, the sum applied must be its starting balance, and the receipt numbers must have no gaps
 * and no repeats.
 */
final class PaymentsCheckCommand extends Command
{
    protected $signature = 'payments:check';

    protected $description = 'Record 20 simultaneous payments of a tenth of the balance of one invoice, on a temporary company, and check that exactly 10 fit';

    private const int PROCESSES = 20;

    private const int SHARES = 10;

    /** Seconds to wait for the chain to accept the invoice, and then for its delivery to end. */
    private const int CHAIN_TIMEOUT = 120;

    private const int DELIVERY_TIMEOUT = 30;

    /** Exit codes of the child processes. */
    private const int RECORDED = 0;

    private const int OVER_BALANCE = 1;

    private const int OTHER_ERROR = 2;

    public function handle(DocumentDrafts $drafts, IssueDocument $issue, InvoiceLedger $ledger, RecordPayment $record): int
    {
        if (($refusal = LocalOnly::refusal($this->getName() ?? 'This command')) !== null) {
            $this->error($refusal);

            return self::FAILURE;
        }

        if (! function_exists('pcntl_fork')) {
            $this->error('The pcntl extension is missing; rebuild the api image with make up.');

            return self::FAILURE;
        }

        $temporary = TemporaryCompany::create('Payments Check', 'PC');

        try {
            // 1 000 000 plus IVA 19 %: 1 190 000.00, whose tenth is exact.
            $invoice = $drafts->create(DocumentType::Invoice, [
                'contact_id' => $temporary->customer()->id,
                'number_template_id' => $temporary->numbering()->id,
                'lines' => [['description' => 'Payments check service', 'quantity' => '1', 'unit_price' => '1000000', 'tax_id' => $temporary->iva()]],
            ], $temporary->user);

            $issue($invoice->id, $temporary->user);
            $this->line('Invoice issued; waiting for the chain: XML, signature, transmission to the local DIAN simulator and its answer...');
            $invoice = $this->waitFor($invoice, fn (Document $document): bool => $document->electronic_status?->isFinal() === true, self::CHAIN_TIMEOUT);

            if ($invoice->electronic_status !== ElectronicStatus::Accepted) {
                $this->error("FAIL: invoice {$invoice->full_number} was not accepted by the local DIAN simulator ({$invoice->electronic_status?->value}).");

                return self::FAILURE;
            }

            $start = BigDecimal::of((string) $invoice->receivable_amount);
            $share = $start->dividedBy(self::SHARES, 2);
            $receipts = NumberTemplate::query()->where('document_type', NumberingType::Payment)->where('is_default', true)->firstOrFail();

            $this->line(sprintf('Temporary company %s: invoice %s accepted by the local DIAN simulator, balance %s.', $temporary->company->name, $invoice->full_number, $start));
            $this->line(sprintf('Starting %d processes at the same moment, each paying %s (a tenth of the balance)...', self::PROCESSES, $share));

            $started = hrtime(true);
            $outcomes = $this->payInParallel($record, $invoice, (string) $share, $temporary);
            $seconds = (hrtime(true) - $started) / 1e9;

            $passed = $this->report($outcomes, $invoice->refresh(), $start, $receipts->refresh(), $ledger, $seconds);

            // The delivery of the invoice runs in the workers too; let it end before the company goes.
            $this->waitFor($invoice, fn (Document $document): bool => $document->delivery_status?->isFinal() ?? true, self::DELIVERY_TIMEOUT);

            return $passed ? self::SUCCESS : self::FAILURE;
        } finally {
            $temporary->delete();
            $this->line('Temporary company deleted.');
        }
    }

    /**
     * Forks the processes; each one waits for the same start instant, records its payment with its
     * own database connection, and reports its outcome in its exit code.
     *
     * @return array<int, int> Outcome of each process.
     */
    private function payInParallel(RecordPayment $record, Document $invoice, string $amount, TemporaryCompany $temporary): array
    {
        $input = [
            'contact_id' => $invoice->contact_id ?? throw new LogicException('An issued invoice has its customer.'),
            'payment_date' => Carbon::now(NumberTemplate::TIMEZONE)->toDateString(),
            'payment_means_code' => '47',
            'reference' => 'Payments check',
            'amount' => $amount,
            'allocations' => [['document_id' => $invoice->id, 'amount' => $amount]],
        ];

        // A forked child must never share the parent's connection.
        DB::disconnect();

        $startAt = microtime(true) + 1.5;
        $children = [];

        for ($process = 0; $process < self::PROCESSES; $process++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not start a process.');
            }

            if ($pid === 0) {
                $outcome = self::RECORDED;

                // Connect first, then wait: every process reaches the database at the same instant.
                DB::connection()->getPdo();
                usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

                try {
                    $record($input, $temporary->user);
                } catch (ValidationException $exception) {
                    $outcome = array_key_exists('allocations.0.amount', $exception->errors()) ? self::OVER_BALANCE : self::OTHER_ERROR;

                    if ($outcome === self::OTHER_ERROR) {
                        fwrite(STDERR, sprintf("Process %d: %s\n", $process + 1, json_encode($exception->errors())));
                    }
                } catch (Throwable $exception) {
                    $outcome = self::OTHER_ERROR;
                    fwrite(STDERR, sprintf("Process %d: %s\n", $process + 1, $exception->getMessage()));
                }

                DB::disconnect();

                exit($outcome);
            }

            $children[] = $pid;
        }

        $outcomes = [];

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $code = is_int($status) && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : false;
            $outcomes[] = $code === false ? self::OTHER_ERROR : $code;
        }

        return $outcomes;
    }

    /**
     * Reads the document again every quarter of a second until the condition holds or the time runs out.
     *
     * @param  callable(Document): bool  $condition
     */
    private function waitFor(Document $document, callable $condition, int $seconds): Document
    {
        $deadline = microtime(true) + $seconds;

        do {
            $document = Document::query()->findOrFail($document->id);

            if ($condition($document)) {
                return $document;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        return $document;
    }

    /**
     * @param  array<int, int>  $outcomes
     */
    private function report(array $outcomes, Document $invoice, BigDecimal $start, NumberTemplate $receipts, InvoiceLedger $ledger, float $seconds): bool
    {
        $counts = array_count_values($outcomes);
        $recorded = $counts[self::RECORDED] ?? 0;
        $rejected = $counts[self::OVER_BALANCE] ?? 0;
        $errors = $counts[self::OTHER_ERROR] ?? 0;

        $payments = Payment::query()->where('status', PaymentStatus::Recorded)->orderBy('number')->get(['id', 'number', 'full_number']);
        $applied = PaymentAllocation::query()->where('document_id', $invoice->id)->get()
            ->reduce(fn (BigDecimal $sum, PaymentAllocation $allocation): BigDecimal => $sum->plus((string) $allocation->amount), BigDecimal::zero());
        $balance = BigDecimal::of((string) $invoice->receivable_amount);
        $open = $ledger->openInvoices($invoice->contact_id);

        $numbers = $payments->pluck('number')->map(fn (mixed $number): int => is_numeric($number) ? (int) $number : 0)->all();
        $first = $numbers[0] ?? 0;
        $last = $numbers === [] ? 0 : $numbers[count($numbers) - 1];
        $repeated = array_values(array_unique(array_diff_assoc($numbers, array_unique($numbers))));
        $missing = $numbers === [] ? [] : array_values(array_diff(range($first, $last), $numbers));

        $this->newLine();
        $this->line(sprintf('Payments: %d recorded and %d rejected for exceeding the balance, %d other errors, in %.2f s.', $recorded, $rejected, $errors, $seconds));
        $results = [$this->verdict($recorded === self::SHARES && $rejected === self::PROCESSES - self::SHARES && $errors === 0, 'exactly 10 payments recorded and 10 rejected for exceeding the balance.', 'expected 10 recorded and 10 rejected for exceeding the balance.')];

        $this->line(sprintf('Invoice %s: balance %s, payment status %s, %d open invoices left for the customer.', $invoice->full_number, $balance->toScale(2), $invoice->payment_status->value ?? '—', count($open)));
        $results[] = $this->verdict($balance->isZero() && $open === [], 'the final balance of the invoice is zero.', 'the final balance of the invoice is not zero.');

        $this->line(sprintf('Applied: %s of a starting balance of %s.', $applied->toScale(2), $start->toScale(2)));
        $results[] = $this->verdict($applied->isEqualTo($start) && BigDecimal::of((string) $invoice->paid_amount)->isEqualTo($start), 'the sum applied is the starting balance.', 'the sum applied is not the starting balance.');

        $this->line(sprintf('Receipts: %s; next number %d.', $payments->pluck('full_number')->implode(', '), $receipts->next_number));
        $results[] = $this->verdict(
            count($numbers) === self::SHARES && $repeated === [] && $missing === [] && $first === $receipts->from_number && $receipts->next_number === $receipts->from_number + self::SHARES,
            sprintf('receipt numbers %s%d to %s%d: no gaps and no repeats.', $receipts->prefix, $first, $receipts->prefix, $last),
            sprintf('receipt numbers: first %d, last %d, next %d, repeated [%s], missing [%s].', $first, $last, $receipts->next_number, implode(', ', $repeated), implode(', ', $missing)),
        );

        $this->newLine();

        if (! in_array(false, $results, true)) {
            $this->info('payments:check passed: of 20 simultaneous payments of a tenth of the balance, exactly 10 were recorded, the invoice ended with nothing to collect, the sum applied is its starting balance, and the receipts have no gaps and no repeats.');

            return true;
        }

        $this->error('payments:check failed.');

        return false;
    }

    private function verdict(bool $ok, string $pass, string $fail): bool
    {
        $ok ? $this->info('   PASS: '.$pass) : $this->error('   FAIL: '.$fail);

        return $ok;
    }
}
