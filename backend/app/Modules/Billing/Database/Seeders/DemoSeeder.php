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
use App\Modules\Catalog\Database\Seeders\DemoSeeder as CatalogDemoSeeder;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
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
 * Six months of SYNTHETIC history, set in Medellín, so the dashboard has a story to tell: 45 invoices
 * in Demo Comercial (a quiet July, a strong August and a busy November) and 15 in Demo Servicios.
 * They are issued with the real issue service, so their numbers are consecutive and their copies
 * coherent. To spread them over the last six months the clock is moved to each moment of issue
 * (Carbon test time) and restored at the end. Dates count back from the day the seeder runs, so the
 * calendar months shift with it. In Demo Comercial the invoices and notes are issued by its owner,
 * its admin and its member, and the payments recorded by the owner and the admin; a user that does
 * not exist, or is not a member of the company, is replaced by the owner.
 *
 * Each issued invoice then runs the real electronic document chain in line (InlineChain): XML,
 * signature, transmission to the local DIAN simulator and its answer, with retries moving the
 * clock instead of waiting. Two SIMULATED outcomes use the failure modes of the simulator: one
 * invoice of Demo Comercial is rejected by a rule, and another gets HTTP 500 on every attempt,
 * so it fails once the attempts run out. The simulator is left in normal mode.
 *
 * Every accepted invoice then goes through the customer delivery in line too: AttachedDocument,
 * PDF and the email with both in a zip, caught by the local mailbox. Invoices to the final consumer
 * have no email, so their delivery ends as not applicable; one invoice has thirty lines.
 *
 * Then what happens after the invoices, in the order of its dates and through the real services:
 * the payments each invoice plan asks for (in full or in part, some days after the issue), and the
 * SYNTHETIC notes (chain and delivery included) and payments of TIMELINE. In Demo Comercial: a
 * discount, a partial return on an invoice paid before (a credit in favor of the customer), the void
 * of a whole invoice, interest on an invoice paid late, a credit note left as a draft, a payment
 * split between two invoices, a check that bounced (its payment voided), invoices paid in part, and
 * overdue invoices of three customers in every aging bucket. In Demo Servicios: a monthly
 * bookkeeping retainer, a service not fully delivered, a rebate and one overdue invoice.
 */
final class DemoSeeder extends Seeder
{
    /** Who acts, by the keys the plans use. */
    private const array USERS = [
        'owner' => 'demo@example.com',
        'admin' => 'admin@example.com',
        'member' => 'member@example.com',
    ];

    private const string RETE_FUENTE_PURCHASES = 'ReteFuente 2.5% (purchases)';

    private const string RETE_FUENTE_SERVICES = 'ReteFuente 4% (services)';

    private const string RETE_IVA = 'ReteIVA 15%';

    private const string RETE_ICA = CatalogDemoSeeder::RETE_ICA;

    /**
     * The invoices of each company, oldest first so their numbers follow their dates, then the
     * drafts. Each row: days before today and Colombian time of issue (null for drafts), customer
     * identification, credit days (null for cash), payment means, lines [item code, quantity,
     * discount %] or free lines [null, quantity, discount %, description, unit price], withholding
     * names, who issues it, and its settlement: null leaves it to TIMELINE or open, [days] is a
     * payment in full that many days after the issue, [days, share] one of that share of its balance.
     *
     * Demo Comercial sells to 990000101 Cordillera Plata, 990000102 Tamarindo Azul (it withholds
     * IVA too), 990000103 Arenal Norte (a slow payer), 990000104 Faro Andino, three people who pay at
     * the counter (9990000105 to 9990000107), the final consumer (222222222222) and US4400000108,
     * a customer abroad. Demo Servicios to 990000201 Valle Alto, 990000202 Quebrada Clara (a monthly
     * bookkeeping retainer), 990000203 Pradera Dorada, 990000204 Sabana Fría, two people (9990000205
     * and 9990000206) and 990000208, a hotel. Planned for a run on November 19, so the month names
     * below are those of that day.
     */
    private const array INVOICES = [
        'commerce' => [
            // June: the oldest invoice of Arenal Norte is still unpaid, more than 90 days past due.
            [168, '09:40', '990000101', 30, '42', [['P-001', '4', '0'], ['P-002', '30', '0'], ['P-008', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [28]],
            [163, '11:15', '990000103', 30, '47', [['P-008', '20', '0'], ['P-002', '15', '0']], [self::RETE_FUENTE_PURCHASES], 'admin', null],
            [157, '15:30', '990000104', 30, '42', [['P-006', '6', '0'], ['P-001', '2', '5']], [self::RETE_FUENTE_PURCHASES], 'owner', [30]],
            [151, '10:05', '222222222222', null, '10', [['P-003', '2', '0'], ['P-007', '1', '0']], [], 'member', [0]],
            [146, '14:20', '990000102', 15, '47', [['P-005', '12', '0'], ['P-008', '8', '0']], [self::RETE_FUENTE_PURCHASES, self::RETE_IVA], 'admin', [15]],
            [143, '09:55', '9990000105', null, '48', [['P-006', '1', '0'], ['P-003', '3', '0']], [], 'member', [0]],
            // July, a quiet month: Tamarindo Azul paid 40% of its invoice and owes the rest.
            [136, '10:30', '990000101', 30, '47', [['P-002', '40', '0'], ['P-005', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [31]],
            [128, '16:10', '9990000106', null, '10', [['S-002', '6', '0'], ['P-003', '1', '0']], [], 'member', [0]],
            [122, '11:45', '990000102', 15, '47', [['P-004', '40', '0'], ['P-007', '10', '0'], ['P-005', '6', '0']], [self::RETE_FUENTE_PURCHASES, self::RETE_IVA], 'admin', [20, '0.40']],
            [115, '09:20', '990000104', 30, '42', [['P-001', '3', '0'], ['S-001', '1', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [30]],
            // August, the strongest month, with the largest order of the half year.
            [109, '10:00', '990000101', 30, '42', [['P-001', '12', '8'], ['P-006', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [30]],
            [106, '15:45', '990000103', 30, '47', [['P-008', '30', '0'], ['P-002', '20', '0']], [self::RETE_FUENTE_PURCHASES], 'admin', [35]],
            [104, '11:10', '990000104', 30, '42', [['P-001', '6', '5'], ['P-006', '4', '0'], ['S-001', '2', '0']], [self::RETE_FUENTE_PURCHASES], 'member', [30]],
            [101, '09:35', '9990000107', null, '49', [['P-005', '2', '0'], ['P-007', '3', '0']], [], 'member', [0]],
            [97, '14:05', '990000102', 15, '47', [['P-005', '20', '0'], ['P-008', '15', '0']], [self::RETE_FUENTE_PURCHASES, self::RETE_IVA], 'admin', null],
            [94, '10:50', '990000101', 30, '47', [['P-002', '50', '0'], ['P-003', '20', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [28]],
            [90, '16:30', '222222222222', null, '10', [['S-002', '4', '0'], ['P-004', '5', '0']], [], 'member', [0]],
            [88, '09:15', '990000104', 30, '42', [['P-006', '8', '0'], ['P-002', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [29]],
            [85, '13:40', '9990000105', null, '48', [['P-001', '1', '0']], [], 'member', [0]],
            [82, '11:25', '990000103', 30, '47', [['P-005', '8', '0'], ['P-008', '12', '0']], [self::RETE_FUENTE_PURCHASES], 'admin', [30, '0.50']],
            // September.
            [78, '10:20', '990000101', 30, '42', [['P-001', '5', '0'], ['P-002', '25', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [30]],
            [74, '15:10', '990000102', 15, '47', [['P-004', '30', '0'], ['P-005', '8', '0']], [self::RETE_FUENTE_PURCHASES, self::RETE_IVA], 'admin', [16]],
            [70, '09:45', '990000104', 30, '42', [['P-006', '3', '0'], ['S-001', '1', '0'], ['P-008', '6', '0']], [self::RETE_FUENTE_PURCHASES], 'member', [31]],
            [66, '11:55', '9990000106', null, '10', [['S-002', '8', '0']], [], 'member', [0]],
            [63, '14:35', '990000103', 30, '47', [['P-008', '25', '0'], ['P-002', '12', '0'], ['S-001', '1', '0']], [self::RETE_FUENTE_PURCHASES], 'admin', null],
            [57, '10:15', '990000101', 30, '47', [['P-005', '15', '0'], ['P-003', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [29]],
            [53, '16:00', '9990000107', null, '49', [['P-006', '2', '0'], ['P-007', '2', '0']], [], 'member', [0]],
            // October; the first five fall within the same days of the month as November so far.
            [48, '09:30', '990000104', 30, '42', [['P-001', '2', '0'], ['P-006', '2', '7.5'], ['S-001', '1', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', null],
            [45, '11:40', '990000101', 30, '42', [['P-001', '4', '5'], ['P-002', '20', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [25, '0.40']],
            [41, '14:15', '990000102', 15, '47', [['P-005', '10', '10'], ['P-008', '12', '0']], [self::RETE_FUENTE_PURCHASES, self::RETE_IVA], 'admin', [25]],
            [37, '10:25', '9990000105', null, '48', [['P-006', '1', '0'], ['S-001', '1', '0']], [], 'member', null],
            [33, '15:50', '990000101', 30, '42', [['P-001', '3', '5'], ['P-002', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', [2]],
            [28, '09:10', '990000103', 30, '47', [['P-008', '10', '0'], ['P-005', '6', '0']], [self::RETE_FUENTE_PURCHASES], 'admin', null],
            [24, '11:00', '222222222222', null, '10', [['P-003', '3', '0'], ['P-004', '5', '0'], ['P-007', '2', '0']], [], 'member', [0]],
            [21, '16:20', '990000102', 15, '47', [['P-004', '25', '0'], ['P-007', '8', '0'], ['P-008', '6', '0']], [self::RETE_FUENTE_PURCHASES, self::RETE_IVA], 'member', null],
            // November so far, with the rejected invoice (12 days ago, issued again 3 days ago) and the failed one.
            [18, '09:05', '990000104', 30, '42', [['P-001', '3', '0'], ['P-006', '4', '0'], ['P-002', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', null],
            [16, '10:40', '990000101', 30, '47', [['P-002', '30', '0'], ['P-008', '20', '0'], ['P-005', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'member', null],
            [14, '15:25', '9990000106', null, '10', [['S-002', '4', '0'], ['P-003', '1', '0']], [], 'member', [0]],
            [12, '11:30', '990000103', 30, '47', [['P-008', '24', '3'], ['P-002', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'admin', null],
            [10, '09:50', '990000101', 30, '42', [['P-001', '4', '5'], ['P-002', '20', '0']], [self::RETE_FUENTE_PURCHASES], 'owner', null],
            [8, '14:30', '9990000107', null, '49', [['P-005', '2', '0'], ['P-007', '3', '0']], [], 'member', null],
            [7, '10:10', '990000102', 15, '47', [['P-005', '10', '0'], ['P-008', '10', '0']], [self::RETE_FUENTE_PURCHASES, self::RETE_IVA], 'admin', null],
            [5, '16:15', '222222222222', null, '10', [['S-002', '3', '0'], ['P-003', '2', '0']], [], 'member', [0]],
            [3, '11:20', '990000103', 30, '47', [['P-008', '24', '3'], ['P-002', '10', '0']], [self::RETE_FUENTE_PURCHASES], 'admin', null],
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
            ], [self::RETE_FUENTE_PURCHASES], 'owner', null],
            // Drafts.
            [null, null, '990000101', 30, '42', [['P-001', '10', '10']], [self::RETE_FUENTE_PURCHASES], 'owner', null],
            [null, null, 'US4400000108', null, '47', [[null, '1', '0', 'Export documentation handling', '350000'], ['P-006', '5', '0']], [], 'owner', null],
        ],
        'services' => [
            // June.
            [165, '10:00', '990000202', 30, '47', [['S-102', '1', '0']], [self::RETE_FUENTE_SERVICES, self::RETE_ICA], 'owner', [30]],
            [150, '11:30', '990000201', 30, '47', [['S-101', '10', '0'], ['S-105', '1', '0']], [self::RETE_FUENTE_SERVICES, self::RETE_IVA], 'owner', [30]],
            // July.
            [135, '10:00', '990000202', 30, '47', [['S-102', '1', '0']], [self::RETE_FUENTE_SERVICES], 'owner', [28]],
            [118, '15:15', '9990000205', null, '48', [['S-106', '2', '0']], [], 'owner', [0]],
            // August.
            [104, '10:00', '990000202', 30, '47', [['S-102', '1', '0'], ['S-103', '1', '0']], [self::RETE_FUENTE_SERVICES, self::RETE_ICA], 'owner', [30]],
            [96, '09:30', '990000203', 15, '47', [['S-104', '1', '0'], ['S-106', '1', '0']], [self::RETE_FUENTE_SERVICES], 'owner', [20]],
            // September: Pradera Dorada never paid its second support plan.
            [74, '10:00', '990000202', 30, '47', [['S-102', '1', '0']], [self::RETE_FUENTE_SERVICES], 'owner', [30]],
            [68, '11:45', '990000208', 15, '47', [['S-108', '40', '5'], ['P-101', '5', '0']], [self::RETE_FUENTE_SERVICES], 'owner', [15]],
            [62, '09:30', '990000203', 15, '47', [['S-104', '1', '0']], [self::RETE_FUENTE_SERVICES], 'owner', null],
            // October.
            [45, '10:00', '990000202', 30, '47', [['S-102', '1', '0']], [self::RETE_FUENTE_SERVICES], 'owner', [29]],
            [38, '10:00', '990000201', 30, '47', [['S-101', '12', '0'], ['S-105', '1', '0']], [self::RETE_FUENTE_SERVICES, self::RETE_IVA], 'owner', [28]],
            [26, '15:15', '9990000206', null, '48', [['S-106', '2', '0'], ['S-107', '3', '0']], [], 'owner', [0]],
            // November so far, not due yet.
            [16, '10:00', '990000202', 30, '47', [['S-102', '1', '0'], ['S-103', '1', '5']], [self::RETE_FUENTE_SERVICES], 'owner', null],
            [9, '11:45', '990000208', 15, '47', [['S-108', '40', '5'], ['P-101', '5', '0']], [self::RETE_FUENTE_SERVICES], 'owner', null],
            [4, '16:00', '990000203', 30, '42', [['S-104', '1', '0'], ['P-102', '10', '0']], [self::RETE_FUENTE_SERVICES, self::RETE_IVA], 'owner', null],
            // Draft.
            [null, null, '990000204', 30, '42', [['S-105', '2', '10'], ['S-101', '4', '0']], [], 'owner', null],
        ],
    ];

    /**
     * SIMULATED outcomes by set and position in its plan: the simulator mode set right before that
     * invoice is issued. count 0 means one request per transmission attempt.
     */
    private const array OUTCOMES = [
        'commerce' => [
            38 => ['mode' => 'reject', 'rule' => 'FAB27b', 'count' => 1],
            40 => ['mode' => 'error_500', 'count' => 0],
        ],
    ];

    /**
     * What happens after the invoices besides their settlements; both run together in the order of
     * their moments (a balance depends on what came before it). Every row starts with the days
     * before today and the Colombian time (null for a draft). Notes: [..., 'note', position of the
     * invoice, type, concept, description, lines, author], lines being 'all' for every line of the
     * invoice, [item code, quantity, price factor] copying that invoice line with another quantity
     * (and a share of its price), or [null, quantity, description, unit price] for a free line
     * without tax; a note takes the withholdings of its invoice. Payments: [..., 'payment', key,
     * payment means, reference, [[position of the invoice, 'full' or a share of its balance], ...],
     * author]. Voids: [..., 'void', key of the payment, reason, author].
     */
    private const array TIMELINE = [
        'commerce' => [
            [70, '10:30', 'note', 20, 'credit_note', '3', 'Discount of 10% on the twenty-five packs of paper, agreed for a late delivery.', [['P-002', '25', '0.10']], 'owner'],
            [35, '09:15', 'note', 30, 'credit_note', '2', 'The invoice was issued to the wrong customer, so it is voided in full.', 'all', 'member'],
            [29, '10:30', 'note', 31, 'credit_note', '1', 'Return of one office chair and five packs of paper that arrived damaged.', [['P-001', '1', '1'], ['P-002', '5', '1']], 'owner'],
            [15, '15:20', 'note', 29, 'debit_note', '1', 'Late payment interest: the invoice was paid ten days after its due date.', [[null, '1', 'Late payment interest, ten days past the due date', '38500']], 'admin'],
            [9, '16:30', 'payment', 'returned', '20', 'CHK-0004471', [[27, 'full']], 'admin'],
            [8, '18:10', 'void', 'returned', 'The bank returned the check for insufficient funds.', 'owner'],
            [4, '10:00', 'payment', 'split', '47', 'TRF-0101877', [[28, 'full'], [36, 'full']], 'admin'],
            [null, null, 'note', 39, 'credit_note', '1', 'Return of one office chair that the customer did not accept on delivery.', [['P-001', '1', '1']], 'member'],
        ],
        'services' => [
            [66, '16:20', 'note', 7, 'credit_note', '1', 'Five fewer guests than the forty invoiced attended the corporate lunch.', [['S-108', '5', '1']], 'owner'],
            [35, '11:40', 'note', 10, 'credit_note', '3', 'Rebate of 10% on the consulting hours, agreed after the review of the project.', [['S-101', '12', '0.10']], 'owner'],
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
        IdentityDemoSeeder::eachCompany(function (Company $company) use ($drafts, $issue): void {
            $this->createNumbering();

            $set = $company->identification_number === IdentityDemoSeeder::NITS[0] ? 'commerce' : 'services';
            $users = $this->users($company);

            try {
                $invoices = $this->createInvoices(self::INVOICES[$set], self::OUTCOMES[$set] ?? [], $company, $drafts, $issue, $users);
            } finally {
                $this->simulator->setMode($company->identification_number, ['mode' => 'normal']);
            }

            $this->runTimeline($this->timeline($set), $invoices, $drafts, $issue, $users);
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
     * The users who act in the company, by the keys of USERS. One that does not exist, or is not a
     * member of the company, is replaced by the owner.
     *
     * @return array<string, User>
     */
    private function users(Company $company): array
    {
        $owner = User::query()->where('email', self::USERS['owner'])->firstOrFail();
        $members = User::query()
            ->whereIn('email', array_values(self::USERS))
            ->whereIn('id', Membership::query()->where('company_id', $company->getKey())->select('user_id'))
            ->get()
            ->keyBy('email');

        return array_map(fn (string $email): User => $members->get($email) ?? $owner, self::USERS);
    }

    /**
     * @param  list<array<int, mixed>>  $plan
     * @param  array<int, array<string, mixed>>  $outcomes
     * @param  array<string, User>  $users
     * @return array<int, string> The id of each invoice, by position in the plan.
     */
    private function createInvoices(array $plan, array $outcomes, Company $company, DocumentDrafts $drafts, IssueDocument $issue, array $users): array
    {
        $created = [];
        $contacts = Contact::query()->pluck('id', 'identification_number');
        $items = Item::query()->pluck('id', 'code');
        $withholdings = Withholding::query()->pluck('id', 'name');
        $today = $this->today();

        foreach ($plan as $position => [$daysAgo, $time, $customer, $creditDays, $means, $lines, $withholdingNames, $issuer]) {
            $issuedAt = $daysAgo === null ? null : $this->at($today, $daysAgo, $time);
            $user = $users[$issuer];

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
     * The steps after the invoices, in the order of their moments: the settlement of each invoice of
     * the plan, as a payment two hours after its time of issue that many days later (recorded by the
     * owner or the admin, in turns), and the steps of TIMELINE. Drafts go last.
     *
     * @return list<array{0: ?Carbon, 1: list<mixed>}>
     */
    private function timeline(string $set): array
    {
        $today = $this->today();
        $steps = [];

        foreach (self::INVOICES[$set] as $position => [$daysAgo, $time, , , $means, , , , $settlement]) {
            if ($settlement === null) {
                continue;
            }

            $at = $this->at($today, $daysAgo, $time)->addDays($settlement[0])->addHours(2);

            if ($at->isFuture()) {
                throw new LogicException("The demo plan pays invoice {$position} after now.");
            }

            $steps[] = [$at, ['payment', "invoice-{$position}", $means, $this->reference($set, $means, $position), [[$position, $settlement[1] ?? 'full']], $position % 2 === 0 ? 'owner' : 'admin']];
        }

        foreach (self::TIMELINE[$set] ?? [] as $row) {
            [$daysAgo, $time] = $row;
            $steps[] = [$daysAgo === null ? null : $this->at($today, $daysAgo, $time), array_slice($row, 2)];
        }

        // Dated steps first, oldest first; steps at the same moment keep their order.
        usort($steps, fn (array $a, array $b): int => [$a[0] === null, $a[0]?->getTimestamp()] <=> [$b[0] === null, $b[0]?->getTimestamp()]);

        return $steps;
    }

    /**
     * @param  list<array{0: ?Carbon, 1: list<mixed>}>  $steps
     * @param  array<int, string>  $invoices
     * @param  array<string, User>  $users
     */
    private function runTimeline(array $steps, array $invoices, DocumentDrafts $drafts, IssueDocument $issue, array $users): void
    {
        $payments = [];

        foreach ($steps as [$at, $step]) {
            match ($step[0]) {
                'note' => $this->createNote($at, $step, $invoices, $drafts, $issue, $users),
                'payment' => $payments[$step[1]] = $this->recordPayment($at, $step, $invoices, $users),
                'void' => $this->voidPayment($at, $step, $payments, $users),
            };
        }

        Carbon::setTestNow();
    }

    /**
     * @param  list<mixed>  $step
     * @param  array<int, string>  $invoices
     * @param  array<string, User>  $users
     */
    private function createNote(?Carbon $issuedAt, array $step, array $invoices, DocumentDrafts $drafts, IssueDocument $issue, array $users): void
    {
        [, $position, $type, $concept, $description, $lines, $author] = $step;

        $user = $users[$author];
        $items = Item::query()->pluck('id', 'code');
        $invoice = Document::query()->with(['lines', 'withholdings'])->findOrFail($invoices[$position]);

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
     * @param  list<mixed>  $step
     * @param  array<int, string>  $invoices
     * @param  array<string, User>  $users
     * @return string The id of the payment.
     */
    private function recordPayment(Carbon $at, array $step, array $invoices, array $users): string
    {
        [, , $means, $reference, $allocations, $author] = $step;

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
        ], $users[$author]);

        Carbon::setTestNow();

        return $payment->getKey();
    }

    /**
     * @param  list<mixed>  $step
     * @param  array<string, string>  $payments
     * @param  array<string, User>  $users
     */
    private function voidPayment(Carbon $at, array $step, array $payments, array $users): void
    {
        [, $key, $reason, $author] = $step;

        Carbon::setTestNow($at);
        ($this->voidPayment)($payments[$key], $reason, $users[$author]);
        Carbon::setTestNow();
    }

    /** A SYNTHETIC bank reference for a settlement: transfers, deposits and checks carry one. */
    private function reference(string $set, string $means, int $position): ?string
    {
        $prefix = match ($means) {
            '47' => 'TRF',
            '42' => 'DEP',
            '20' => 'CHK',
            default => null,
        };

        return $prefix === null ? null : sprintf('%s-%s%05d', $prefix, $set === 'commerce' ? '41' : '22', 10000 + $position * 731);
    }

    /** The start of today in Colombia. */
    private function today(): Carbon
    {
        return Carbon::now(NumberTemplate::TIMEZONE)->startOfDay();
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
