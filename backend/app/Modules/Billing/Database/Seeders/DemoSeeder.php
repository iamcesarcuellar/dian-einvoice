<?php

namespace App\Modules\Billing\Database\Seeders;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Billing\Services\InvoiceDrafts;
use App\Modules\Billing\Services\IssueInvoice;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Dian\Pipeline\ElectronicDocumentPipeline;
use App\Modules\Dian\Pipeline\InlineChain;
use App\Modules\Dian\Simulator\DianSimulator;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * DEMO DATA for local development. The invoice range uses the public values of the DIAN test set
 * (prefix SETP, resolution 18760000001 and its technical key); the note ranges are synthetic.
 *
 * The SYNTHETIC invoices are issued with the real issue service, so their numbers are consecutive
 * and their copies coherent. To spread them over the last 30 days the clock is moved to each
 * moment of issue (Carbon test time) and restored at the end.
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
            6 => ['mode' => 'reject', 'rule' => 'FAB27b', 'count' => 1],
            7 => ['mode' => 'error_500', 'count' => 0],
        ],
    ];

    public function __construct(
        private readonly InlineChain $chain,
        private readonly DianSimulator $simulator,
        private readonly ElectronicDocumentPipeline $pipeline,
    ) {}

    public function run(InvoiceDrafts $drafts, IssueInvoice $issue): void
    {
        $user = User::query()->where('email', 'demo@example.com')->firstOrFail();

        IdentityDemoSeeder::eachCompany(function (Company $company) use ($drafts, $issue, $user): void {
            $this->createNumbering();

            $set = $company->identification_number === IdentityDemoSeeder::NITS[0] ? 'commerce' : 'services';

            try {
                $this->createInvoices(self::INVOICES[$set], self::OUTCOMES[$set] ?? [], $company, $drafts, $issue, $user);
            } finally {
                $this->simulator->setMode($company->identification_number, ['mode' => 'normal']);
            }
        });
    }

    private function createNumbering(): void
    {
        NumberTemplate::create([
            'document_type' => DocumentType::Invoice,
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

        foreach ([DocumentType::CreditNote->value => 'NC', DocumentType::DebitNote->value => 'ND'] as $type => $prefix) {
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
     */
    private function createInvoices(array $plan, array $outcomes, Company $company, InvoiceDrafts $drafts, IssueInvoice $issue, User $user): void
    {
        $contacts = Contact::query()->pluck('id', 'identification_number');
        $items = Item::query()->pluck('id', 'code');
        $withholdings = Withholding::query()->pluck('id', 'name');
        $today = Carbon::now(NumberTemplate::TIMEZONE)->startOfDay();

        foreach ($plan as $position => [$daysAgo, $time, $customer, $creditDays, $means, $lines, $withholdingNames]) {
            $issuedAt = $daysAgo === null
                ? null
                : $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time)->utc();

            // Each draft is written a few minutes before it is issued.
            Carbon::setTestNow($issuedAt?->copy()->subMinutes(12));

            $issueDay = ($issuedAt ?? Carbon::now())->copy()->setTimezone(NumberTemplate::TIMEZONE);

            $document = $drafts->create([
                'contact_id' => $contacts[$customer],
                'payment_form' => $creditDays === null ? '1' : '2',
                'payment_means_code' => $means,
                'due_date' => $creditDays === null ? null : $issueDay->copy()->addDays($creditDays)->toDateString(),
                'lines' => array_map(fn (array $line): array => $line[0] === null
                    ? ['description' => $line[3], 'unit_code' => '94', 'quantity' => $line[1], 'discount_rate' => $line[2], 'unit_price' => $line[4], 'tax_id' => null]
                    : ['item_id' => $items[$line[0]], 'quantity' => $line[1], 'discount_rate' => $line[2]], $lines),
                'withholding_ids' => array_map(fn (string $name): string => $withholdings[$name], $withholdingNames),
            ], $user);

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
    }
}
