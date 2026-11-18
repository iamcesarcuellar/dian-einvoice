<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Ports\OpenInvoice;
use Brick\Math\BigDecimal;

/**
 * Accounts receivable by customer, from the open invoices the ledger lists: what each customer
 * owes and how long past due, in the usual aging buckets (not due yet, 1 to 30, 31 to 60, 61 to 90
 * and more than 90 days after the due date), its credit in favor (negative balances, kept apart),
 * and the totals of every column.
 */
final class ReceivablesReport
{
    /** Bucket => the last day past due it holds; null holds the rest. */
    public const array BUCKETS = [
        'current' => 0,
        'days_1_30' => 30,
        'days_31_60' => 60,
        'days_61_90' => 90,
        'over_90' => null,
    ];

    public function __construct(private readonly InvoiceLedger $ledger) {}

    /**
     * @return array{customers: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function build(): array
    {
        $rows = [];
        $totals = $this->empty();

        foreach ($this->ledger->openInvoices() as $invoice) {
            $rows[$invoice->contactId] ??= [
                'contact_id' => $invoice->contactId,
                'customer_name' => $invoice->customerName,
                'customer_identification' => $invoice->customerIdentification,
                ...$this->empty(),
            ];

            $this->add($rows[$invoice->contactId], $invoice);
            $this->add($totals, $invoice);
        }

        $customers = array_map(fn (array $row): array => $this->format($row), array_values($rows));

        usort($customers, fn (array $a, array $b): int => BigDecimal::of($b['total'])->compareTo($a['total']) ?: strcmp($a['customer_name'], $b['customer_name']));

        return ['customers' => $customers, 'totals' => $this->format($totals)];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(): array
    {
        return [
            'invoices' => 0,
            'overdue_invoices' => 0,
            'total' => BigDecimal::zero(),
            ...array_fill_keys(array_keys(self::BUCKETS), BigDecimal::zero()),
            'customer_credit' => BigDecimal::zero(),
            'oldest_days_past_due' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function add(array &$row, OpenInvoice $invoice): void
    {
        $balance = BigDecimal::of($invoice->balance);

        if ($balance->isNegative()) {
            $row['customer_credit'] = $row['customer_credit']->plus($balance->negated());

            return;
        }

        $row['invoices']++;
        $row['total'] = $row['total']->plus($balance);
        $bucket = $this->bucket($invoice->daysPastDue);
        $row[$bucket] = $row[$bucket]->plus($balance);

        if ($invoice->overdue()) {
            $row['overdue_invoices']++;
            $row['oldest_days_past_due'] = max($row['oldest_days_past_due'], $invoice->daysPastDue);
        }
    }

    private function bucket(int $daysPastDue): string
    {
        foreach (self::BUCKETS as $bucket => $upTo) {
            if ($upTo === null || $daysPastDue <= $upTo) {
                return $bucket;
            }
        }

        return 'over_90';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function format(array $row): array
    {
        return array_map(fn ($value) => $value instanceof BigDecimal ? (string) $value->toScale(2) : $value, $row);
    }
}
