<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

use Modules\Invoicing\Models\InvoiceCreditApplication;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;

interface InvoicingServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createInvoice(array $data, array $items): SalesInvoice;

    public function createInvoiceFromOrder(SalesOrder $order, array $overrides = []): SalesInvoice;

    public function createInvoiceFromDeliveryNote(SalesDeliveryNote $deliveryNote, array $overrides = []): SalesInvoice;

    public function createInvoiceFromMilestone(
        string $projectId,
        string $milestoneId,
        string $customerId,
        float $amount,
        string $description,
        string $tenantId
    ): SalesInvoice;

    public function approveInvoice(SalesInvoice $invoice, string $userId): SalesInvoice;

    public function postInvoice(SalesInvoice $invoice, string $userId): SalesInvoice;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function issueCreditNote(SalesInvoice $originalInvoice, array $data, array $items): SalesInvoice;

    public function applyCreditNote(SalesInvoice $creditNote, SalesInvoice $targetInvoice, float $amount, string $userId): InvoiceCreditApplication;
}
