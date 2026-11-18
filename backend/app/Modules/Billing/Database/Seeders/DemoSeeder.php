<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentLine;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Dian\Pipeline\ElectronicDocumentPipeline;
use App\Modules\Dian\Pipeline\InlineChain;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Ports\OpenInvoice;
use App\Modules\Payments\Services\RecordPayment;
use App\Modules\Payments\Services\VoidPayment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * DEMO DATA for local development. The invoice range uses the public values of the DIAN test set
 * (prefix SETP, resolution 18760000001 and its technical key); the note ranges are synthetic, and
 * the receipt range (RC) is the one every company gets.
 *
 * The SYNTHETIC invoices are issued with the real issue service, so their numbers are consecutive
 * and their copies coherent. To spread them over the last four months the clock is moved to each
 * moment of issue (Carbon test time) and restored at the end. The oldest have short terms, so the
 * receivables fill every aging bucket.
 *
 * Each issued invoice then runs the real electronic document chain in line (InlineChain): XML,
 * signature, transmission to the local DIAN simulator and its answer, with retries moving the
 * clock instead of waiting. Two SIMULATED outcomes use the failure modes of the simulator: one
 * invoice of Demo Comercial is rejected by a rule, and another gets HTTP 500 on every attempt,
 * so it fails once the attempts run out. The simulator is left in normal mode.
 *
 * Every accepted invoice then goes through the customer delivery in line too: AttachedDocument,
 * PDF and the email with both in a zip, caught by the local mailbox. The one to the final consumer
 * has no email, so its delivery ends as not applicable; one invoice has thirty lines.
 *
 * Then what happens after the invoices, in the order of its dates and through the real services:
 * SYNTHETIC credit and debit notes (chain and delivery included) and payments. In Demo Comercial a
 * partial return on an invoice paid before (a credit in favor of the customer), the void of a whole
 * invoice, interest on an invoice paid late, a credit note left as a draft, invoices paid in full,
 * one paid in part, one payment split between two invoices, one voided, and overdue invoices of
 * three customers. In Demo Servicios a partial rebate, two payments and one overdue invoice.
 */
final class DemoSeeder extends Seeder
{
    /**
     * Days before today and Colombian time of issue (null for drafts), customer identification,
     * credit days (null for cash), payment means, lines [item code, quantity, discount %] or free
     * lines [null, quantity, discount %, description, unit price], and withholding names.
     */
    private const array INVOICES = [
        'commerce' => [
            // Older invoices with short terms: more than 90, 61 to 90 and 31 to 60 days past due.
            [118, '10:30', '990000103', 15, '47', [['P-008', '6', '0'], ['P-002', '8', '0']], ['ReteFuente 2.5% (purchases)']],
            [82, '14:15', '990000102', 15, '42', [['P-004', '4', '0'], ['P-007', '2', '0']], []],
            [50, '09:50', '990000101', 10, '47', [['P-005', '4', '0']], ['ReteFuente 2.5% (purchases)']],
            [40, '11:30', '990000104', 15, '42', [['P-001', '1', '0'], ['S-002', '2', '0']], ['ReteFuente 2.5% (purchases)']],
            [29, '09:20', '990000101', 30, '42', [['P-001', '4', '5'], ['P-002', '20', '0']], ['ReteFuente 2.5% (purchases)']],
            [26, '11:05', '222222222222', null, '10', [['P-003', '3', '0'], ['P-004', '5', '0'], ['P-007', '2', '0']], []],
            [23, '15:40', '990000102', 15, '47', [['P-005', '10', '10'], ['P-008', '12', '0']], ['ReteFuente 2.5% (purchases)', 'ReteIVA 15%']],
            [19, '10:10', '9990000105', null, '48', [['P-006', '1', '0'], ['S-001', '1', '0']], []],
            [15, '16:25', '990000104', 30, '42', [['P-001', '2', '0'], ['P-006', '2', '7.5'], ['S-001', '1', '0']], ['ReteFuente 2.5% (purchases)']],
            [11, '12:00', '9990000106', null, '10', [['S-002', '4', '0'], ['P-003', '1', '0']], []],
            [6, '09:45', '990000103', 30, '47', [['P-008', '24', '3'], ['P-002', '10', '0']], ['ReteFuente 2.5% (purchases)']],
            [2, '14:30', '9990000107', null, '49', [['P-005', '2', '0'], ['P-007', '3', '0']], []],
            [null, null, '990000101', 30, '42', [['P-001', '10', '10']], ['ReteFuente 2.5% (purchases)']],
            [null, null, 'US4400000108', null, '47', [[null, '1', '0', 'Export documentation handling', '350000'], ['P-006', '5', '0']], []],
            // Thirty lines, so its PDF runs over two pages with the table header repeated.
            [1, '11:15', '990000104', 30, '42', [
                ['P-001', '1', '0'],
                ['P-002', '12', '0'],
                ['P-003', '6', '0'],
                ['P-004', '10', '0'],
                ['P-005', '3', '5'],
                ['P-006', '2', '0'],
                ['P-007', '4', '0'],
                ['P-008', '8', '0'],
                ['S-001', '1', '0'],
                ['S-002', '6', '0'],
                ['P-001', '2', '5'],
                ['P-002', '25', '0'],
                ['P-003', '4', '0'],
                ['P-004', '15', '0'],
                ['P-005', '5', '0'],
                ['P-006', '1', '10'],
                ['P-007', '6', '0'],
                ['P-008', '12', '0'],
                ['S-001', '1', '0'],
                ['S-002', '10', '0'],
                ['P-001', '1', '0'],
                ['P-002', '30', '0'],
                ['P-003', '8', '2.5'],
                ['P-004', '20', '0'],
                ['P-005', '2', '0'],
                ['P-006', '3', '0'],
                ['P-007', '5', '0'],
                ['P-008', '6', '3'],
                ['S-001', '2', '0'],
                ['S-002', '8', '0'],
            ], ['ReteFuente 2.5% (purchases)']],
        ],
        'services' => [
            // Due 33 days ago and never paid.
            [48, '10:20', '990000203', 15, '47', [['S-104', '1', '0'], ['S-106', '1', '0']], ['ReteFuente 4% (services)']],
            [27, '10:00', '990000201', 30, '47', [['S-101', '12', '0'], ['S-105', '1', '0']], ['ReteFuente 4% (services)', 'ReteIVA 15%']],
            [20, '09:30', '990000202', 30, '42', [['S-102', '1', '0'], ['S-103', '1', '5']], ['ReteFuente 4% (services)']],
            [13, '15:15', '9990000205', null, '48', [['S-106', '2', '0'], ['S-107', '3', '0']], []],
            [8, '11:45', '990000208', 15, '47', [['S-108', '40', '5'], ['P-101', '5', '0']], ['ReteFuente 4% (services)']],
            [3, '16:00', '990000203', 30, '42', [['S-104', '1', '0'], ['P-102', '10', '0']], ['ReteFuente 4% (services)', 'ReteIVA 15%']],
            [null, null, '990000204', 30, '42', [['S-105', '2', '10'], ['S-101', '4', '0']], []],
        ],
    ];

    /**
     * SIMULATED outcomes by set and position in its plan: the simulator mode set right before that
     * invoice is issued. count 0 means one request per transmission attempt.
     */
    private const array OUTCOMES = [
        'commerce' => [
            10 => ['mode' => 'reject', 'rule' => 'FAB27b', 'count' => 1],
            11 => ['mode' => 'error_500', 'count' => 0],
        ],
    ];

    /**
     * What happens after the invoices, in the order of its dates (a balance depends on what came
     * before it). Notes: ['note', position of the invoice, type, days before today and Colombian
     * time (null for a draft), concept, description, lines], lines being 'all' for every line of the
     * invoice, [item code, quantity, price factor] copying that invoice line with another quantity
     * (and a share of its price), or [null, quantity, description, unit price] for a free line
     * without tax; a note takes the withholdings of its invoice. Payments: ['payment', key, days
     * before today, time, payment means, reference, [[position of the invoice, 'full' or a share of
     * its balance], ...]]. Voids: ['void', key of the payment, days before today, time, reason].
     */
    private const array TIMELINE = [
        'commerce' => [
            ['payment', 'partial', 60, '10:00', '47', 'TRF-0099412', [[1, '0.40']]],
            ['payment', 'paid-before-return', 27, '15:00', '42', 'DEP-5512094', [[4, 'full']]],
            ['payment', 'consumer', 26, '11:12', '10', null, [[5, 'full']]],
            ['note', 4, 'credit_note', 25, '10:30', '1', 'Return of one office chair and five packs of paper that arrived damaged.', [['P-001', '1', '1'], ['P-002', '5', '1']]],
            ['note', 7, 'credit_note', 17, '09:15', '2', 'The invoice was issued to the wrong customer, so it is voided in full.', 'all'],
            ['payment', 'cash', 11, '12:05', '10', null, [[9, 'full']]],
            ['payment', 'split', 5, '09:30', '47', 'TRF-0101877', [[3, 'full'], [8, 'full']]],
            ['payment', 'late', 2, '10:00', '47', 'TRF-0102530', [[6, 'full']]],
            ['note', 6, 'debit_note', 2, '15:20', '1', 'Late payment interest: the invoice was paid six days after its due date.', [[null, '1', 'Late payment interest, six days past the due date', '42350']]],
            ['payment', 'returned', 1, '16:30', '20', 'CHK-0004471', [[14, 'full']]],
            ['void', 'returned', 1, '18:10', 'The bank returned the check for insufficient funds.'],
            ['note', 9, 'credit_note', null, null, '1', 'Return of one hour of technical support that was not delivered.', [['S-002', '1', '1']]],
        ],
        'services' => [
            ['note', 1, 'credit_note', 24, '11:40', '3', 'Rebate of 10% on the consulting hours, agreed after the review of the project.', [['S-101', '12', '0.10']]],
            ['payment', 'transfer', 20, '10:00', '47', 'TRF-2200317', [[1, 'full']]],
            ['payment', 'card', 13, '15:20', '48', null, [[3, 'full']]],
        ],
    ];

    public function __construct(
        private readonly InlineChain $chain,
        private readonly DianSimulator $simulator,
        private readonly ElectronicDocumentPipeline $pipeline,
        private readonly InvoiceLedger $ledger,
        private readonly RecordPayment $recordPayment,
        private readonly VoidPayment $voidPayment,
    ) {}

    public function run(DocumentDrafts $drafts, IssueDocument $issue): void
    {
        $user = User::query()->where('email', 'demo@example.com')->firstOrFail();

        IdentityDemoSeeder::eachCompany(function (Company $company) use ($drafts, $issue, $user): void {
            $this->createNumbering();

            $set = $company->identification_number === IdentityDemoSeeder::NITS[0] ? 'commerce' : 'services';

            try {
                $invoices = $this->createInvoices(self::INVOICES[$set], self::OUTCOMES[$set] ?? [], $company, $drafts, $issue, $user);
            } finally {
                $this->simulator->setMode($company->identification_number, ['mode' => 'normal']);
            }

            $this->runTimeline(self::TIMELINE[$set] ?? [], $invoices, $drafts, $issue, $user);
        });
    }

    private function createNumbering(): void
    {
        NumberTemplate::create([
            'document_type' => NumberingType::Invoice,
            'prefix' => 'SETP',
            'from_number' => 990000000,
            'to_number' => 995000000,
            'next_number' => 990000000,
            'resolution_number' => '18760000001',
            'resolution_date' => '2019-01-19',
            'valid_from' => '2019-01-19',
            'valid_to' => '2030-01-19',
            'technical_key' => 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c',
            'is_default' => true,
        ]);

        foreach ([NumberingType::CreditNote->value => 'NC', NumberingType::DebitNote->value => 'ND'] as $type => $prefix) {
            NumberTemplate::create([
                'document_type' => $type,
                'prefix' => $prefix,
                'from_number' => 1,
                'to_number' => 99999999,
                'next_number' => 1,
                'is_default' => true,
            ]);
        }
    }

    /**
     * @param  list<array<int, mixed>>  $plan
     * @param  array<int, array<string, mixed>>  $outcomes
     * @return array<int, string> The id of each invoice, by position in the plan.
     */
    private function createInvoices(array $plan, array $outcomes, Company $company, DocumentDrafts $drafts, IssueDocument $issue, User $user): array
    {
        $created = [];
        $contacts = Contact::query()->pluck('id', 'identification_number');
        $items = Item::query()->pluck('id', 'code');
        $withholdings = Withholding::query()->pluck('id', 'name');
        $today = Carbon::now(NumberTemplate::TIMEZONE)->startOfDay();

        foreach ($plan as $position => [$daysAgo, $time, $customer, $creditDays, $means, $lines, $withholdingNames]) {
            $issuedAt = $daysAgo === null ? null : $this->at($today, $daysAgo, $time);

            // Each draft is written a few minutes before it is issued.
            Carbon::setTestNow($issuedAt?->copy()->subMinutes(12));

            $issueDay = ($issuedAt ?? Carbon::now())->copy()->setTimezone(NumberTemplate::TIMEZONE);

            $document = $drafts->create(DocumentType::Invoice, [
                'contact_id' => $contacts[$customer],
                'payment_form' => $creditDays === null ? '1' : '2',
                'payment_means_code' => $means,
                'due_date' => $creditDays === null ? null : $issueDay->copy()->addDays($creditDays)->toDateString(),
                'lines' => array_map(fn (array $line): array => $line[0] === null
                    ? ['description' => $line[3], 'unit_code' => '94', 'quantity' => $line[1], 'discount_rate' => $line[2], 'unit_price' => $line[4], 'tax_id' => null]
                    : ['item_id' => $items[$line[0]], 'quantity' => $line[1], 'discount_rate' => $line[2]], $lines),
                'withholding_ids' => array_map(fn (string $name): string => $withholdings[$name], $withholdingNames),
            ], $user);

            $created[$position] = $document->getKey();

            if ($issuedAt !== null) {
                if (isset($outcomes[$position])) {
                    $outcome = $outcomes[$position];
                    $this->simulator->setMode($company->identification_number, [
                        ...$outcome,
                        'count' => $outcome['count'] ?: $this->pipeline->maxAttempts(),
                    ]);
                }

                Carbon::setTestNow($issuedAt);
                $issue($document->getKey(), $user);
                $this->chain->run($document->getKey());
            }
        }

        Carbon::setTestNow();

        return $created;
    }

    /**
     * @param  list<array<int, mixed>>  $timeline
     * @param  array<int, string>  $invoices
     */
    private function runTimeline(array $timeline, array $invoices, DocumentDrafts $drafts, IssueDocument $issue, User $user): void
    {
        $today = Carbon::now(NumberTemplate::TIMEZONE)->startOfDay();
        $payments = [];

        foreach ($timeline as $step) {
            match ($step[0]) {
                'note' => $this->createNote($step, $invoices, $today, $drafts, $issue, $user),
                'payment' => $payments[$step[1]] = $this->recordPayment($step, $invoices, $today, $user),
                'void' => $this->voidPayment($step, $payments, $today, $user),
            };
        }

        Carbon::setTestNow();
    }

    /**
     * @param  array<int, mixed>  $step
     * @param  array<int, string>  $invoices
     */
    private function createNote(array $step, array $invoices, Carbon $today, DocumentDrafts $drafts, IssueDocument $issue, User $user): void
    {
        [, $position, $type, $daysAgo, $time, $concept, $description, $lines] = $step;

        $items = Item::query()->pluck('id', 'code');
        $invoice = Document::query()->with(['lines', 'withholdings'])->findOrFail($invoices[$position]);
        $issuedAt = $daysAgo === null ? null : $this->at($today, $daysAgo, $time);

        // A draft is written a few hours ago; an issued note a few minutes before it is issued.
        Carbon::setTestNow($issuedAt?->copy()->subMinutes(9) ?? Carbon::now()->subHours(3));

        $note = $drafts->create(DocumentType::from($type), [
            'related_document_id' => $invoice->getKey(),
            'reason_code' => $concept,
            'reason_text' => $description,
            'lines' => $lines === 'all'
                ? $invoice->lines->map(fn (DocumentLine $line): array => $this->copyLine($line, (string) $line->quantity, '1'))->all()
                : array_map(fn (array $line): array => $line[0] === null
                    ? ['description' => $line[2], 'unit_code' => '94', 'quantity' => $line[1], 'unit_price' => $line[3], 'discount_rate' => '0', 'tax_id' => null]
                    : $this->copyLine($invoice->lines->firstWhere('item_id', $items[$line[0]]), $line[1], $line[2]), $lines),
            'withholding_ids' => $invoice->withholdings->pluck('withholding_id')->filter()->values()->all(),
        ], $user);

        if ($issuedAt !== null) {
            Carbon::setTestNow($issuedAt);
            $issue($note->getKey(), $user);
            $this->chain->run($note->getKey());
        }

        Carbon::setTestNow();
    }

    /**
     * Records a payment through the payments module, for the balance its invoices have at that
     * moment ('full') or a share of it, rounded to whole pesos.
     *
     * @param  array<int, mixed>  $step
     * @param  array<int, string>  $invoices
     * @return string The id of the payment.
     */
    private function recordPayment(array $step, array $invoices, Carbon $today, User $user): string
    {
        [, , $daysAgo, $time, $means, $reference, $allocations] = $step;

        $at = $this->at($today, $daysAgo, $time);
        Carbon::setTestNow($at);

        $contactId = (string) Document::query()->whereKey($invoices[$allocations[0][0]])->value('contact_id');
        $open = [];

        foreach ($this->ledger->openInvoices($contactId) as $invoice) {
            $open[$invoice->id] = $invoice;
        }

        $lines = array_map(function (array $allocation) use ($open, $invoices): array {
            [$position, $share] = $allocation;
            $invoice = $open[$invoices[$position]] ?? throw new LogicException("The demo payment names invoice {$position}, which has nothing left to collect.");
            /** @var OpenInvoice $invoice */
            $balance = BigDecimal::of($invoice->balance);
            $amount = $share === 'full' ? $balance : $balance->multipliedBy($share)->toScale(0, RoundingMode::HalfEven);

            return ['document_id' => $invoice->id, 'amount' => (string) $amount->toScale(2)];
        }, $allocations);

        $payment = ($this->recordPayment)([
            'contact_id' => $contactId,
            'payment_date' => $at->copy()->setTimezone(NumberTemplate::TIMEZONE)->toDateString(),
            'payment_means_code' => $means,
            'reference' => $reference,
            'notes' => null,
            'allocations' => $lines,
        ], $user);

        Carbon::setTestNow();

        return $payment->getKey();
    }

    /**
     * @param  array<int, mixed>  $step
     * @param  array<string, string>  $payments
     */
    private function voidPayment(array $step, array $payments, Carbon $today, User $user): void
    {
        [, $key, $daysAgo, $time, $reason] = $step;

        Carbon::setTestNow($this->at($today, $daysAgo, $time));
        ($this->voidPayment)($payments[$key], $reason, $user);
        Carbon::setTestNow();
    }

    /** A Colombian day and time before today, as an instant in UTC. */
    private function at(Carbon $today, int $daysAgo, string $time): Carbon
    {
        return $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time)->utc();
    }

    /**
     * The input of a note line that corrects a line of the invoice: the same item, price, discount
     * and tax, with its own quantity and a share of the price.
     *
     * @return array<string, mixed>
     */
    private function copyLine(?DocumentLine $line, string $quantity, string $priceFactor): array
    {
        if ($line === null) {
            throw new LogicException('The demo note names an item its invoice does not have.');
        }

        return [
            'item_id' => $line->item_id,
            'code' => $line->code,
            'description' => $line->description,
            'unit_code' => $line->unit_code,
            'quantity' => $quantity,
            'unit_price' => (string) BigDecimal::of((string) $line->unit_price)->multipliedBy($priceFactor)->toScale(2, RoundingMode::HalfEven),
            'discount_rate' => (string) $line->discount_rate,
            'tax_id' => $line->tax_id,
        ];
    }
}
