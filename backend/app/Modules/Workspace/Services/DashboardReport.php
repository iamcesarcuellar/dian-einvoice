<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Enums\NumberingStatus;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Billing\Models\DocumentPayment;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Dian\Enums\CertificateStatus;
use App\Modules\Dian\Models\SigningCertificate;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Payments\Enums\PaymentEventType;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentEvent;
use App\Modules\Payments\Services\ReceivablesReport;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything the dashboard shows for the current company, from aggregate queries (sums, counts and
 * groups computed by the database, never a loop over every invoice). Dates are Colombian calendar
 * dates. Invoiced means invoices accepted by the local DIAN simulator, by their total (payable
 * amount, before withholdings); collected means payments recorded and not voided, which are net of
 * the withholdings customers apply. Indicators compare the month to date with the same days of the
 * previous month; receivables and overdue compare with the balances as they stood that day, rebuilt
 * from the notes and payments dated up to it.
 */
final class DashboardReport
{
    public const string CURRENCY = 'COP';

    /** Months of the invoiced and collected series, the current one included. */
    private const int MONTHS = 6;

    /** Days of the summary of electronic documents, today included. */
    private const int ELECTRONIC_DAYS = 30;

    /** Days of the top customers, today included, and how many of them. */
    private const int TOP_CUSTOMER_DAYS = 90;

    private const int TOP_CUSTOMERS = 5;

    private const int ATTENTION_LIMIT = 10;

    private const int ACTIVITY_LIMIT = 15;

    /** A numbering is highlighted once more than this share of its range is used, in percent. */
    private const int USAGE_WARNING = 80;

    /** A numbering or a certificate is highlighted when it ends in fewer days than this. */
    private const int DAYS_WARNING = CertificateStatus::WARNING_DAYS;

    /**
     * Timeline entries of documents the activity shows. The steps of the chain in between (XML
     * built and signed, each transmission and email attempt, files generated) are left out; a sent
     * email is shown, as delivered.
     */
    private const array DOCUMENT_ACTIVITY = [
        DocumentEventType::Issued,
        DocumentEventType::Accepted,
        DocumentEventType::Rejected,
        DocumentEventType::XmlFailed,
        DocumentEventType::TransmissionFailed,
        DocumentEventType::RetryRequested,
        DocumentEventType::ResendRequested,
        DocumentEventType::DeliveryFailed,
        DocumentEventType::DeliveryRequested,
        DocumentEventType::PdfRegenerationRequested,
    ];

    /** The activity type of an email attempt the mail server accepted. */
    private const string DELIVERED = 'delivered';

    public function __construct(private readonly CurrentCompany $currentCompany) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $today = Carbon::now(NumberTemplate::TIMEZONE)->startOfDay();
        $previousTo = $today->copy()->subMonthNoOverflow();

        $period = [
            'from' => $today->copy()->startOfMonth()->toDateString(),
            'to' => $today->toDateString(),
            'previous_from' => $previousTo->copy()->startOfMonth()->toDateString(),
            'previous_to' => $previousTo->toDateString(),
        ];

        $open = $this->openInvoices($period['to']);

        return [
            'currency' => self::CURRENCY,
            'today' => $period['to'],
            'has_invoices' => Document::query()->where('type', DocumentType::Invoice)->where('status', DocumentStatus::Issued)->exists(),
            'period' => $period,
            'kpis' => $this->kpis($period, $open),
            'monthly' => $this->monthly($today),
            'aging' => $open['aging'],
            'electronic' => $this->electronic($today),
            'attention' => $this->attention(),
            'notices' => [
                'numbering' => $this->numbering($today),
                'certificate' => $this->certificate(),
            ],
            'top_customers' => $this->topCustomers($today),
            'activity' => $this->activity(),
        ];
    }

    /**
     * @param  array<string, string>  $period
     * @param  array<string, mixed>  $open
     * @return array<string, array<string, mixed>>
     */
    private function kpis(array $period, array $open): array
    {
        $invoiced = $this->acceptedInvoices()
            ->whereBetween('issue_date', [$period['previous_from'], $period['to']])
            ->toBase()
            ->selectRaw(self::periodColumns('issue_date', 'payable_amount'), self::periodBindings($period))
            ->first();

        $collected = Payment::query()
            ->where('status', PaymentStatus::Recorded)
            ->whereBetween('payment_date', [$period['previous_from'], $period['to']])
            ->toBase()
            ->selectRaw(self::periodColumns('payment_date', 'amount'), self::periodBindings($period))
            ->first();

        $previous = $this->openInvoicesAsOf($period['previous_to']);

        return [
            'invoiced' => $this->kpi($invoiced?->amount, $invoiced?->count, $invoiced?->previous_amount, $invoiced?->previous_count),
            'collected' => $this->kpi($collected?->amount, $collected?->count, $collected?->previous_amount, $collected?->previous_count),
            'receivable' => $this->kpi($open['amount'], $open['count'], $previous->amount ?? null, $previous->count ?? null),
            'overdue' => $this->kpi($open['overdue_amount'], $open['overdue_count'], $previous->overdue_amount ?? null, $previous->overdue_count ?? null),
        ];
    }

    /** Sum and count of the period and of the same days of the previous month, in one query. */
    private static function periodColumns(string $date, string $amount): string
    {
        return implode(', ', [
            "coalesce(sum(case when {$date} between ? and ? then {$amount} end), 0) as amount",
            "count(case when {$date} between ? and ? then 1 end) as count",
            "coalesce(sum(case when {$date} between ? and ? then {$amount} end), 0) as previous_amount",
            "count(case when {$date} between ? and ? then 1 end) as previous_count",
        ]);
    }

    /**
     * @param  array<string, string>  $period
     * @return list<string>
     */
    private static function periodBindings(array $period): array
    {
        return [
            $period['from'], $period['to'], $period['from'], $period['to'],
            $period['previous_from'], $period['previous_to'], $period['previous_from'], $period['previous_to'],
        ];
    }

    /**
     * The receivables now, from the balance each invoice stores (the same rows as the Receivables
     * report: accepted invoices with something positive left to collect), with what is overdue and
     * the aging buckets of the report.
     *
     * @return array{amount: mixed, count: mixed, overdue_amount: mixed, overdue_count: mixed, aging: array<string, string>}
     */
    private function openInvoices(string $today): array
    {
        $days = 'datediff(?, coalesce(due_date, issue_date))';
        $columns = [
            'coalesce(sum(receivable_amount), 0) as amount',
            'count(*) as count',
            'coalesce(sum(case when coalesce(due_date, issue_date) < ? then receivable_amount end), 0) as overdue_amount',
            'count(case when coalesce(due_date, issue_date) < ? then 1 end) as overdue_count',
        ];
        $bindings = [$today, $today];
        $lower = null;

        // Each bucket holds the days past due above the end of the one before it, up to its own.
        foreach (ReceivablesReport::BUCKETS as $bucket => $upTo) {
            $range = array_values(array_filter([
                $lower === null ? null : "{$days} > {$lower}",
                $upTo === null ? null : "{$days} <= {$upTo}",
            ]));

            $columns[] = 'coalesce(sum(case when '.implode(' and ', $range)." then receivable_amount end), 0) as bucket_{$bucket}";
            $bindings = [...$bindings, ...array_fill(0, count($range), $today)];
            $lower = $upTo;
        }

        $row = $this->acceptedInvoices()
            ->where('receivable_amount', '>', 0)
            ->toBase()
            ->selectRaw(implode(', ', $columns), $bindings)
            ->first();

        $aging = [];

        foreach (array_keys(ReceivablesReport::BUCKETS) as $bucket) {
            $aging[$bucket] = $this->money($row?->{"bucket_{$bucket}"});
        }

        $aging['total'] = $this->money($row?->amount);

        return [
            'amount' => $row?->amount,
            'count' => $row?->count,
            'overdue_amount' => $row?->overdue_amount,
            'overdue_count' => $row?->overdue_count,
            'aging' => $aging,
        ];
    }

    /**
     * The receivables as they stood at the end of a past day, rebuilt from what was dated up to it:
     * the amount due of each accepted invoice issued by then, less its accepted credit notes, plus its
     * accepted debit notes, less the payments dated by then that were not voided by the end of it.
     * Only positive balances count, as in the Receivables report; overdue are those due before it.
     */
    private function openInvoicesAsOf(string $date): ?object
    {
        $companyId = $this->currentCompany->id();
        $endOfDay = Carbon::parse($date, NumberTemplate::TIMEZONE)->endOfDay()->utc()->format('Y-m-d H:i:s');
        $issued = DocumentStatus::Issued->value;
        $accepted = ElectronicStatus::Accepted->value;
        $credit = DocumentType::CreditNote->value;
        $debit = DocumentType::DebitNote->value;

        return DB::selectOne(<<<'SQL'
            select
                coalesce(sum(case when b.balance > 0 then b.balance end), 0) as amount,
                count(case when b.balance > 0 then 1 end) as count,
                coalesce(sum(case when b.balance > 0 and b.due < ? then b.balance end), 0) as overdue_amount,
                count(case when b.balance > 0 and b.due < ? then 1 end) as overdue_count
            from (
                select
                    coalesce(i.due_date, i.issue_date) as due,
                    i.amount_due - coalesce(n.credited, 0) + coalesce(n.debited, 0) - coalesce(p.paid, 0) as balance
                from documents i
                left join (
                    select
                        related_document_id,
                        sum(case when type = ? then amount_due else 0 end) as credited,
                        sum(case when type = ? then amount_due else 0 end) as debited
                    from documents
                    where company_id = ? and type in (?, ?) and status = ? and electronic_status = ? and issue_date <= ?
                    group by related_document_id
                ) n on n.related_document_id = i.id
                left join (
                    select document_id, sum(amount) as paid
                    from document_payments
                    where company_id = ? and payment_date <= ? and (status = ? or released_at > ?)
                    group by document_id
                ) p on p.document_id = i.id
                where i.company_id = ? and i.type = ? and i.status = ? and i.electronic_status = ? and i.issue_date <= ?
            ) b
            SQL, [
            $date, $date,
            $credit, $debit,
            $companyId, $credit, $debit, $issued, $accepted, $date,
            $companyId, $date, DocumentPayment::APPLIED, $endOfDay,
            $companyId, DocumentType::Invoice->value, $issued, $accepted, $date,
        ]);
    }

    /**
     * Invoiced and collected by month, the oldest first and the current month last, with the months
     * without movement as zero.
     *
     * @return list<array<string, mixed>>
     */
    private function monthly(Carbon $today): array
    {
        $range = [$today->copy()->startOfMonth()->subMonthsNoOverflow(self::MONTHS - 1)->toDateString(), $today->toDateString()];

        $invoiced = $this->acceptedInvoices()
            ->whereBetween('issue_date', $range)
            ->toBase()
            ->selectRaw("date_format(issue_date, '%Y-%m') as month, coalesce(sum(payable_amount), 0) as amount, count(*) as count")
            ->groupByRaw("date_format(issue_date, '%Y-%m')")
            ->get()
            ->keyBy('month');

        $collected = Payment::query()
            ->where('status', PaymentStatus::Recorded)
            ->whereBetween('payment_date', $range)
            ->toBase()
            ->selectRaw("date_format(payment_date, '%Y-%m') as month, coalesce(sum(amount), 0) as amount, count(*) as count")
            ->groupByRaw("date_format(payment_date, '%Y-%m')")
            ->get()
            ->keyBy('month');

        $months = [];

        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $month = $today->copy()->startOfMonth()->subMonthsNoOverflow($back)->format('Y-m');

            $months[] = [
                'month' => $month,
                'invoiced' => $this->money($invoiced->get($month)?->amount),
                'invoices' => (int) ($invoiced->get($month)?->count ?? 0),
                'collected' => $this->money($collected->get($month)?->amount),
                'payments' => (int) ($collected->get($month)?->count ?? 0),
            ];
        }

        return $months;
    }

    /**
     * Invoices and notes issued in the last 30 days, by the state of their electronic document; the
     * four states of a chain still running count as processing.
     *
     * @return array<string, int>
     */
    private function electronic(Carbon $today): array
    {
        $rows = Document::query()
            ->where('status', DocumentStatus::Issued)
            ->where('issue_date', '>=', $today->copy()->subDays(self::ELECTRONIC_DAYS - 1)->toDateString())
            ->whereNotNull('electronic_status')
            ->toBase()
            ->selectRaw('electronic_status, count(*) as total')
            ->groupBy('electronic_status')
            ->get();

        $processing = array_map(fn (ElectronicStatus $status): string => $status->value, ElectronicStatus::processing());
        $summary = ['days' => self::ELECTRONIC_DAYS, 'total' => 0, 'accepted' => 0, 'processing' => 0, 'rejected' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $state = in_array($row->electronic_status, $processing, true) ? 'processing' : (string) $row->electronic_status;
            $summary[$state] = ($summary[$state] ?? 0) + (int) $row->total;
            $summary['total'] += (int) $row->total;
        }

        return $summary;
    }

    /**
     * Documents that need someone: rejected by the local simulator, failed in the chain, or whose
     * delivery failed. Every one of them is counted; the newest few are listed with the reason.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function attention(): array
    {
        $stopped = [ElectronicStatus::Rejected->value, ElectronicStatus::Failed->value];

        $query = Document::query()
            ->where('status', DocumentStatus::Issued)
            ->where(fn (Builder $query) => $query
                ->whereIn('electronic_status', $stopped)
                ->orWhere('delivery_status', DeliveryStatus::Failed));

        $total = (clone $query)->count();

        $documents = $query
            ->orderByRaw('coalesce(case when electronic_status in (?, ?) then electronic_status_at else delivery_status_at end, updated_at) desc', $stopped)
            ->orderByDesc('id')
            ->limit(self::ATTENTION_LIMIT)
            ->get(['id', 'type', 'full_number', 'customer_snapshot', 'issue_date', 'electronic_status', 'electronic_status_at', 'electronic_failed_step', 'dian_messages', 'dian_status_description', 'delivery_status', 'delivery_status_at', 'delivery_error', 'updated_at']);

        return [
            'total' => $total,
            'items' => $documents->map(function (Document $document): array {
                $problem = match ($document->electronic_status) {
                    ElectronicStatus::Rejected => 'rejected',
                    ElectronicStatus::Failed => 'failed',
                    default => 'delivery_failed',
                };

                return [
                    'id' => $document->id,
                    'type' => $document->type->value,
                    'full_number' => $document->full_number,
                    'customer_name' => $document->customer_snapshot['legal_name'] ?? null,
                    'issue_date' => $document->issue_date?->toDateString(),
                    'problem' => $problem,
                    'detail' => match ($problem) {
                        'rejected' => $this->firstRejection($document),
                        'failed' => $document->electronic_failed_step,
                        default => $document->delivery_error,
                    },
                    'since' => $problem === 'delivery_failed' ? $document->delivery_status_at : $document->electronic_status_at,
                ];
            })->values()->all(),
        ];
    }

    /** The first rule that rejected the document, as "FAB27b: message". */
    private function firstRejection(Document $document): ?string
    {
        foreach ($document->dian_messages ?? [] as $message) {
            if (($message['kind'] ?? null) !== 'rejection') {
                continue;
            }

            $rule = $message['rule'] ?? null;
            $text = trim((string) ($message['message'] ?? ''));

            return match (true) {
                $rule === null => $text,
                $text === '' => (string) $rule,
                default => "{$rule}: {$text}",
            };
        }

        return $document->dian_status_description;
    }

    /**
     * Use of the invoice numbering (the default active one, else the first active one) and the days
     * left of its validity. Null when the company has none.
     *
     * @return array<string, mixed>|null
     */
    private function numbering(Carbon $today): ?array
    {
        $numbering = NumberTemplate::query()
            ->where('document_type', NumberingType::Invoice)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();

        if ($numbering === null) {
            return null;
        }

        $used = $numbering->usedCount();
        $total = $numbering->totalCount();
        $usage = BigDecimal::of($used)->multipliedBy(100)->dividedBy(max(1, $total), 1, RoundingMode::HalfUp);
        $daysLeft = $numbering->valid_to === null
            ? null
            : (int) round($today->diffInDays(Carbon::parse($numbering->valid_to->toDateString(), NumberTemplate::TIMEZONE)->startOfDay(), false));
        $status = $numbering->status();

        return [
            'id' => $numbering->id,
            'prefix' => $numbering->prefix,
            'used' => $used,
            'total' => $total,
            'usage' => (string) $usage,
            'valid_to' => $numbering->valid_to?->toDateString(),
            'days_left' => $daysLeft,
            'status' => $status->value,
            'warning' => $usage->isGreaterThan(self::USAGE_WARNING)
                || ($daysLeft !== null && $daysLeft < self::DAYS_WARNING)
                || $status !== NumberingStatus::Active,
        ];
    }

    /**
     * The days left of the signing certificate. Null when the company has none.
     *
     * @return array<string, mixed>|null
     */
    private function certificate(): ?array
    {
        $certificate = SigningCertificate::current();

        if ($certificate === null) {
            return null;
        }

        $status = $certificate->status();
        $daysLeft = (int) floor(Carbon::now()->diffInDays($certificate->valid_to, false));

        return [
            'subject' => $certificate->subject,
            'valid_to' => $certificate->valid_to,
            'days_left' => $daysLeft,
            'status' => $status->value,
            'is_test' => $certificate->is_test,
            'warning' => $daysLeft < self::DAYS_WARNING || ! $status->usable(),
        ];
    }

    /**
     * The customers with the most invoiced in the last 90 days, by the total of their accepted invoices.
     *
     * @return list<array<string, mixed>>
     */
    private function topCustomers(Carbon $today): array
    {
        $rows = $this->acceptedInvoices()
            ->where('issue_date', '>=', $today->copy()->subDays(self::TOP_CUSTOMER_DAYS - 1)->toDateString())
            ->toBase()
            ->selectRaw('contact_id, coalesce(sum(payable_amount), 0) as amount, count(*) as invoices')
            ->groupBy('contact_id')
            ->orderByDesc('amount')
            ->orderBy('contact_id')
            ->limit(self::TOP_CUSTOMERS)
            ->get();

        $names = Contact::query()->whereIn('id', $rows->pluck('contact_id')->all())->pluck('legal_name', 'id');

        return $rows->map(fn (object $row): array => [
            'contact_id' => $row->contact_id,
            'name' => $names->get($row->contact_id),
            'amount' => $this->money($row->amount),
            'invoices' => (int) $row->invoices,
        ])->values()->all();
    }

    /**
     * The newest entries of the timelines of documents and payments, with who acted and what on.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(): array
    {
        $documentEvents = DocumentEvent::query()
            ->with(['actor:id,name', 'apiKey:id,name'])
            ->where(fn (Builder $query) => $query
                ->whereIn('type', array_map(fn (DocumentEventType $type): string => $type->value, self::DOCUMENT_ACTIVITY))
                ->orWhere(fn (Builder $sent) => $sent
                    ->where('type', DocumentEventType::EmailAttempt->value)
                    ->where('payload->outcome', EmailOutcome::Sent->value)))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        $paymentEvents = PaymentEvent::query()
            ->with(['actor:id,name', 'apiKey:id,name'])
            ->whereIn('type', [PaymentEventType::Recorded->value, PaymentEventType::Voided->value])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();

        $documents = Document::query()
            ->whereIn('id', $documentEvents->pluck('document_id')->unique()->values()->all())
            ->get(['id', 'type', 'full_number', 'customer_snapshot', 'payable_amount'])
            ->keyBy('id');

        $payments = Payment::query()
            ->whereIn('id', $paymentEvents->pluck('payment_id')->unique()->values()->all())
            ->get(['id', 'full_number', 'customer_name', 'amount', 'void_reason'])
            ->keyBy('id');

        $entries = [];

        foreach ($documentEvents as $event) {
            $document = $documents->get($event->document_id);

            if (! $document instanceof Document) {
                continue;
            }

            $type = $event->type === DocumentEventType::EmailAttempt ? self::DELIVERED : $event->type->value;

            $entries[] = [
                'id' => $event->id,
                'source' => 'document',
                'type' => $type,
                'occurred_at' => $event->occurred_at,
                'actor' => $this->actor($event->actorSummary()),
                'subject' => [
                    'type' => $document->type->value,
                    'id' => $document->id,
                    'number' => $document->full_number,
                    'customer_name' => $document->customer_snapshot['legal_name'] ?? null,
                ],
                'amount' => $type === DocumentEventType::Issued->value ? (string) $document->payable_amount : null,
                'detail' => $this->documentDetail($type, $event->payload ?? []),
            ];
        }

        foreach ($paymentEvents as $event) {
            $payment = $payments->get($event->payment_id);

            if (! $payment instanceof Payment) {
                continue;
            }

            $entries[] = [
                'id' => $event->id,
                'source' => 'payment',
                'type' => $event->type->value,
                'occurred_at' => $event->occurred_at,
                'actor' => $this->actor($event->actorSummary()),
                'subject' => [
                    'type' => 'payment',
                    'id' => $payment->id,
                    'number' => $payment->full_number,
                    'customer_name' => $payment->customer_name,
                ],
                'amount' => (string) $payment->amount,
                'detail' => $event->type === PaymentEventType::Voided ? ($payment->void_reason ?? ($event->payload['reason'] ?? null)) : null,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$b['occurred_at'], $b['id']] <=> [$a['occurred_at'], $a['id']]);

        return array_slice($entries, 0, self::ACTIVITY_LIMIT);
    }

    /**
     * A short detail of a document entry: the first rule of a rejection, the recipient of an email,
     * or the step that failed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function documentDetail(string $type, array $payload): ?string
    {
        $detail = match ($type) {
            DocumentEventType::Rejected->value => $payload['rules'][0] ?? null,
            self::DELIVERED, DocumentEventType::DeliveryRequested->value => $payload['recipient'] ?? null,
            DocumentEventType::XmlFailed->value, DocumentEventType::DeliveryFailed->value => $payload['step'] ?? null,
            default => null,
        };

        return $detail === null ? null : (string) $detail;
    }

    /**
     * @param  array{type: string, id: string, name: string}|null  $summary
     * @return array{type: string, name: string}|null
     */
    private function actor(?array $summary): ?array
    {
        return $summary === null ? null : ['type' => $summary['type'], 'name' => $summary['name']];
    }

    /**
     * Accepted invoices: the ones that count as invoiced and can be collected.
     *
     * @return Builder<Document>
     */
    private function acceptedInvoices(): Builder
    {
        return Document::query()
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Issued)
            ->where('electronic_status', ElectronicStatus::Accepted);
    }

    /**
     * An indicator: the amount and count now and in the previous period, and the change in percent
     * with one decimal, null when there is nothing to compare with.
     *
     * @return array<string, mixed>
     */
    private function kpi(mixed $amount, mixed $count, mixed $previousAmount, mixed $previousCount): array
    {
        $current = BigDecimal::of($this->money($amount));
        $previous = BigDecimal::of($this->money($previousAmount));

        return [
            'amount' => (string) $current,
            'count' => (int) ($count ?? 0),
            'previous_amount' => (string) $previous,
            'previous_count' => (int) ($previousCount ?? 0),
            'change' => $previous->isZero()
                ? null
                : (string) $current->minus($previous)->multipliedBy(100)->dividedBy($previous->abs(), 1, RoundingMode::HalfUp),
        ];
    }

    /** A sum of the database as a decimal string with 2 decimals; null is zero. */
    private function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HalfEven);
    }
}
