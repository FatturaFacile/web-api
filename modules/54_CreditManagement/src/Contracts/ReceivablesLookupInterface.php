<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Contracts;

/**
 * What credit management reads from the sales side: customers, their open receivables and committed orders, and the
 * credit limit held in the sales credit profile. Amounts are decimal strings, dates are Y-m-d.
 */
interface ReceivablesLookupInterface
{
    /**
     * The display name of a customer of the company, or null when the customer does not exist in it.
     */
    public function customerName(string $tenantId, string $customerId): ?string;

    /**
     * @param  list<string>  $customerIds
     * @return array<string, string> customer id => display name
     */
    public function customerNames(string $tenantId, array $customerIds): array;

    /**
     * Issued, unpaid and partially paid invoices (credit notes and proformas excluded) with what is still due.
     *
     * @return list<array{invoice_id: string, customer_id: string, invoice_number: string, issued_on: string, due_on: string|null, currency: string, gross: string, outstanding: string}>
     */
    public function openInvoices(string $tenantId, ?string $customerId = null): array;

    /**
     * Gross value of the committed orders of the customer that are not billed yet.
     */
    public function committedOrders(string $tenantId, string $customerId): string;

    /**
     * Gross value of the invoices issued in the period (both ends included), for one customer or for all of them.
     */
    public function invoicedBetween(string $tenantId, ?string $customerId, string $from, string $to): string;

    /**
     * Date of the first invoice issued to the customer.
     */
    public function firstInvoiceDate(string $tenantId, string $customerId): ?string;

    /**
     * The credit limit currently held for the customer (0 means no limit).
     */
    public function creditLimit(string $tenantId, string $customerId): string;

    /**
     * Stores the approved credit limit where the sales side checks it.
     */
    public function applyCreditLimit(string $tenantId, string $customerId, string $limit, string $userId): void;
}
