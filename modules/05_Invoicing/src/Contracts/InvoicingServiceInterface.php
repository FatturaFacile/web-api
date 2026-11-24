<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\InvoiceCreditApplication;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;

/**
 * The single writer of the sales documents (invoices, credit and debit notes, proformas, down payments). Every flow that
 * creates or issues a sales document goes through it, so numbering, VAT, fiscal rules, exchange rate, payment schedule,
 * ledger posting and events are the same whatever the origin of the document.
 */
interface InvoicingServiceInterface
{
    /**
     * Creates a draft document; a proforma is numbered from its own series at once.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createInvoice(array $data, array $items): SalesInvoice;

    /**
     * Creates and issues a document in one transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function issueInvoice(array $data, array $items, string $userId): SalesInvoice;

    /**
     * Drafts the invoice of the open quantities of an order (or of the requested `lines`).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createInvoiceFromOrder(SalesOrder $order, array $overrides = []): SalesInvoice;

    /**
     * Invoices the open quantities of an order (or the requested `lines`) and issues the invoice.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function issueInvoiceFromOrder(SalesOrder $order, array $overrides, string $userId): SalesInvoice;

    /**
     * Drafts the invoice of a delivery note.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createInvoiceFromDeliveryNote(SalesDeliveryNote $deliveryNote, array $overrides = []): SalesInvoice;

    /**
     * Invoices a delivery note and issues the invoice.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function issueInvoiceFromDeliveryNote(SalesDeliveryNote $deliveryNote, array $overrides, string $userId): SalesInvoice;

    /**
     * Issues a debit note (TD05) adjusting an issued invoice.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function issueDebitNote(SalesInvoice $originalInvoice, array $data, array $items, string $userId): SalesInvoice;

    public function createInvoiceFromMilestone(
        string $projectId,
        string $milestoneId,
        string $customerId,
        float $amount,
        string $description,
        string $tenantId,
        ?float $taxRate = null
    ): SalesInvoice;

    public function approveInvoice(SalesInvoice $invoice, string $userId): SalesInvoice;

    public function postInvoice(SalesInvoice $invoice, string $userId): SalesInvoice;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function issueCreditNote(SalesInvoice $originalInvoice, array $data, array $items): SalesInvoice;

    public function applyCreditNote(SalesInvoice $creditNote, SalesInvoice $targetInvoice, string|float $amount, string $userId): InvoiceCreditApplication;
}
