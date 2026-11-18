<?php

namespace App\Modules\Payments\Ports;

use Brick\Math\BigDecimal;

/**
 * An invoice as Payments sees it: its number, customer, dates and what is left to collect. Amounts
 * are decimal strings with 2 decimals; a negative balance is a credit in favor of the customer.
 */
final readonly class OpenInvoice
{
    public function __construct(
        public string $id,
        public string $number,
        public string $contactId,
        public string $customerName,
        public ?string $customerIdentification,
        public string $issueDate,
        /** Null for a cash invoice: it is due on its issue date. */
        public ?string $dueDate,
        public string $currency,
        /** Payable amount less withholdings. */
        public string $amountDue,
        /** Net of the accepted credit notes, and of the accepted debit notes. */
        public string $credited,
        public string $debited,
        public string $paid,
        public string $balance,
        /** Days after the due date (or the issue date for cash); zero or less is not due yet. */
        public int $daysPastDue,
    ) {}

    public function overdue(): bool
    {
        return $this->daysPastDue > 0 && BigDecimal::of($this->balance)->isPositive();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'contact_id' => $this->contactId,
            'customer_name' => $this->customerName,
            'customer_identification' => $this->customerIdentification,
            'issue_date' => $this->issueDate,
            'due_date' => $this->dueDate,
            'currency' => $this->currency,
            'amount_due' => $this->amountDue,
            'credited' => $this->credited,
            'debited' => $this->debited,
            'paid' => $this->paid,
            'balance' => $this->balance,
            'days_past_due' => max(0, $this->daysPastDue),
            'overdue' => $this->overdue(),
        ];
    }
}
