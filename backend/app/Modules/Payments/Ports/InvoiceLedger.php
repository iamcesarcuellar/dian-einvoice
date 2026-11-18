<?php

namespace App\Modules\Payments\Ports;

/**
 * What Payments needs from Billing, and all it may use: the open invoices of a customer, and an
 * invoice locked to apply an amount to it or to give one back. Billing implements it; the payments
 * module never reads the tables of Billing. Apply and release run inside the transaction of the
 * caller and keep the invoice locked until it ends, so the caller locks several invoices always in
 * the same order (by id). When payments move to a service of their own, this becomes its client.
 */
interface InvoiceLedger
{
    /**
     * Accepted invoices with something left to collect, or a credit in favor of the customer: of
     * one customer, or of every customer when $contactId is null. Oldest due first.
     *
     * @return list<OpenInvoice>
     */
    public function openInvoices(?string $contactId = null): array;

    /**
     * Locks the invoice and applies the amount to it.
     *
     * @throws InvoiceNotPayable when it is not an accepted invoice of that customer.
     * @throws AmountExceedsBalance when the amount is above what is left to collect.
     */
    public function apply(string $invoiceId, string $contactId, string $amount, PaymentReference $payment): OpenInvoice;

    /** Locks the invoice and gives back the amount a voided payment applied to it. */
    public function release(string $invoiceId, PaymentReference $payment): OpenInvoice;
}
