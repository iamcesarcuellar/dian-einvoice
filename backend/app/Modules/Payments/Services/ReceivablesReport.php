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
 *
 * @phpstan-type Amounts array{invoices: int, overdue_invoices: int, total: BigDecimal, current: BigDecimal, days_1_30: BigDecimal, days_31_60: BigDecimal, days_61_90: BigDecimal, over_90: BigDecimal, customer_credit: BigDecimal, oldest_days_past_due: int}
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
                'customer' => [
                    'contact_id' => $invoice->contactId,
                    'customer_name' => $invoice->customerName,
                    'customer_identification' => $invoice->customerIdentification,
                ],
                'amounts' => $this->empty(),
            ];

            $rows[$invoice->contactId]['amounts'] = $this->add($rows[$invoice->contactId]['amounts'], $invoice);
            $totals = $this->add($totals, $invoice);
        }

        $rows = array_values($rows);

        usort($rows, fn (array $a, array $b): int => $b['amounts']['total']->compareTo($a['amounts']['total']) ?: strcmp($a['customer']['customer_name'], $b['customer']['customer_name']));

        $customers = array_map(fn (array $row): array => [...$row['customer'], ...$this->format($row['amounts'])], $rows);

        return ['customers' => $customers, 'totals' => $this->format($totals)];
    }

    /**
     * Every amount at zero. The buckets are those of BUCKETS, written out so each one has its type.
     *
     * @return Amounts
     */
    private function empty(): array
    {
        return [
            'invoices' => 0,
            'overdue_invoices' => 0,
            'total' => BigDecimal::zero(),
            'current' => BigDecimal::zero(),
            'days_1_30' => BigDecimal::zero(),
            'days_31_60' => BigDecimal::zero(),
            'days_61_90' => BigDecimal::zero(),
            'over_90' => BigDecimal::zero(),
            'customer_credit' => BigDecimal::zero(),
            'oldest_days_past_due' => 0,
        ];
    }

    /**
     * The amounts with one more open invoice.
     *
     * @param  Amounts  $row
     * @return Amounts
     */
    private function add(array $row, OpenInvoice $invoice): array
    {
        $balance = BigDecimal::of($invoice->balance);

        if ($balance->isNegative()) {
            $row['customer_credit'] = $row['customer_credit']->plus($balance->negated());

            return $row;
        }

        $row['invoices']++;
        $row['total'] = $row['total']->plus($balance);
        $bucket = $this->bucket($invoice->daysPastDue);
        $row[$bucket] = $row[$bucket]->plus($balance);

        if ($invoice->overdue()) {
            $row['overdue_invoices']++;
            $row['oldest_days_past_due'] = max($row['oldest_days_past_due'], $invoice->daysPastDue);
        }

        return $row;
    }

    /**
     * @return key-of<self::BUCKETS>
     */
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
     * The amounts as decimal strings with two decimals; the counts stay numbers.
     *
     * @param  Amounts  $row
     * @return array<string, int|string>
     */
    private function format(array $row): array
    {
        return array_map(fn (BigDecimal|int $value): int|string => $value instanceof BigDecimal ? (string) $value->toScale(2) : $value, $row);
    }
}
