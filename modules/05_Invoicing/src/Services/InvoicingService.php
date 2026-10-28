<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\InvoiceApprovedEvent;
use Modules\Invoicing\Events\InvoiceDraftedEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\InvoiceCreditApplication;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesOrder;

final class InvoicingService implements InvoicingServiceInterface
{
    public function __construct(
        private readonly PricingAndDiscountServiceInterface $pricingService,
        private readonly CustomerLedgerServiceInterface $ledgerService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $items
     */
    public function createInvoice(array $data, array $items): SalesInvoice
    {
        $tenantId = (string) $data['tenant_id'];
        $invoiceId = (string) Str::uuid7();

        $totals = $this->pricingService->calculateOrderTotals($items);

        $invoiceNumber = $data['invoice_number'] ?? 'INV-'.date('Y').'-'.strtoupper(Str::random(6));

        $invoice = SalesInvoice::create([
            'id' => $invoiceId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $data['contact_id'],
            'invoice_type' => $data['invoice_type'] ?? 'standard',
            'status' => 'draft',
            'invoice_number' => $invoiceNumber,
            'date' => $data['date'] ?? now()->toDateString(),
            'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
            'currency' => $data['currency'] ?? 'EUR',
            'exchange_rate' => (float) ($data['exchange_rate'] ?? 1.0),
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_total'],
            'total_net' => $totals['net_total'],
            'total_tax' => $totals['tax_total'],
            'total_gross' => $totals['gross_total'],
            'receivable_amount' => $totals['gross_total'],
            'total_paid' => 0.0,
            'payment_status' => 'unpaid',
            'jurisdiction' => $data['jurisdiction'] ?? 'IT',
            'split_payment' => (bool) ($data['split_payment'] ?? false),
            'reverse_charge' => (bool) ($data['reverse_charge'] ?? false),
            'payment_method' => $data['payment_method'] ?? 'MP05',
            'payment_terms' => $data['payment_terms'] ?? 'TP02',
            'bank_name' => $data['bank_name'] ?? null,
            'bank_iban' => $data['bank_iban'] ?? null,
            'sales_order_id' => $data['sales_order_id'] ?? null,
            'delivery_note_id' => $data['delivery_note_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'project_milestone_id' => $data['project_milestone_id'] ?? null,
            'customer_agreement_id' => $data['customer_agreement_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1.0);
            $unitPrice = (float) ($item['unit_price'] ?? 0.0);
            $taxRate = (float) ($item['tax_rate'] ?? 22.0);
            $discountPct = (float) ($item['discount_percentage'] ?? 0.0);
            $lineNet = round(($qty * $unitPrice) * (1 - ($discountPct / 100.0)), 2);

            SalesInvoiceItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'invoice_id' => $invoiceId,
                'sales_order_item_id' => $item['sales_order_item_id'] ?? null,
                'delivery_note_item_id' => $item['delivery_note_item_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Invoice item',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'discount_percentage' => $discountPct,
                'line_total' => $lineNet,
                'revenue_account_id' => $item['revenue_account_id'] ?? null,
                'tax_account_id' => $item['tax_account_id'] ?? null,
                'uom' => $item['uom'] ?? 'EA',
                'notes' => $item['notes'] ?? null,
            ]);
        }

        $this->eventDispatcher->dispatch(new InvoiceDraftedEvent(
            invoiceId: $invoiceId,
            invoiceNumber: $invoiceNumber,
            customerId: (string) $invoice->contact_id,
            totalGross: (float) $invoice->total_gross,
            currency: (string) $invoice->currency,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $invoice->load('items');
    }

    public function createInvoiceFromOrder(SalesOrder $order, array $overrides = []): SalesInvoice
    {
        $order->load('items');
        $tenantId = (string) $order->tenant_id;

        $items = [];
        foreach ($order->items as $orderItem) {
            $toInvoice = (float) $orderItem->quantity - (float) $orderItem->quantity_invoiced;
            if ($toInvoice <= 0) {
                continue;
            }

            $items[] = [
                'sales_order_item_id' => (string) $orderItem->id,
                'product_id' => (string) $orderItem->product_id,
                'description' => (string) $orderItem->description,
                'quantity' => $toInvoice,
                'unit_price' => (float) $orderItem->unit_price,
                'tax_rate' => (float) $orderItem->tax_rate,
                'discount_percentage' => (float) $orderItem->discount_percentage,
                'uom' => (string) $orderItem->uom,
            ];
        }

        $data = array_merge([
            'tenant_id' => $tenantId,
            'contact_id' => (string) $order->contact_id,
            'sales_order_id' => (string) $order->id,
            'currency' => $order->currency ?? 'EUR',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'notes' => 'Generated from Sales Order '.$order->order_number,
        ], $overrides);

        $invoice = $this->createInvoice($data, $items);

        // Update items invoiced quantity and order billing status
        foreach ($order->items as $orderItem) {
            $orderItem->update([
                'quantity_invoiced' => $orderItem->quantity,
            ]);
        }

        $order->update([
            'billing_status' => 'billed',
        ]);

        return $invoice;
    }

    public function createInvoiceFromDeliveryNote(SalesDeliveryNote $deliveryNote, array $overrides = []): SalesInvoice
    {
        $deliveryNote->load('items');
        $tenantId = (string) $deliveryNote->tenant_id;

        $items = [];
        foreach ($deliveryNote->items as $dnItem) {
            $items[] = [
                'delivery_note_item_id' => (string) $dnItem->id,
                'sales_order_item_id' => $dnItem->sales_order_item_id,
                'product_id' => $dnItem->product_id,
                'description' => (string) $dnItem->description,
                'quantity' => (float) $dnItem->quantity,
                'unit_price' => (float) $dnItem->unit_price,
                'tax_rate' => (float) $dnItem->tax_rate,
                'discount_percentage' => 0.0,
            ];
        }

        $data = array_merge([
            'tenant_id' => $tenantId,
            'contact_id' => (string) $deliveryNote->contact_id,
            'sales_order_id' => $deliveryNote->sales_order_id,
            'delivery_note_id' => (string) $deliveryNote->id,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'notes' => 'Generated from Delivery Note '.$deliveryNote->ddt_number,
        ], $overrides);

        $invoice = $this->createInvoice($data, $items);

        $deliveryNote->update([
            'status' => 'invoiced',
        ]);

        return $invoice;
    }

    public function createInvoiceFromMilestone(
        string $projectId,
        string $milestoneId,
        string $customerId,
        float $amount,
        string $description,
        string $tenantId
    ): SalesInvoice {
        $data = [
            'tenant_id' => $tenantId,
            'contact_id' => $customerId,
            'project_id' => $projectId,
            'project_milestone_id' => $milestoneId,
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'notes' => 'Project Milestone Billing: '.$description,
        ];

        $items = [
            [
                'description' => $description,
                'quantity' => 1.0,
                'unit_price' => $amount,
                'tax_rate' => 22.0,
                'discount_percentage' => 0.0,
            ],
        ];

        return $this->createInvoice($data, $items);
    }

    public function approveInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        $invoice->update([
            'status' => 'approved',
        ]);

        $this->eventDispatcher->dispatch(new InvoiceApprovedEvent(
            invoiceId: (string) $invoice->id,
            approvedBy: $userId,
            tenantId: (string) $invoice->tenant_id,
            companyId: (string) $invoice->tenant_id
        ));

        return $invoice;
    }

    public function postInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        $invoice->load('items');
        $tenantId = (string) $invoice->tenant_id;

        $revenueLines = [];
        $taxLines = [];

        foreach ($invoice->items as $item) {
            $revenueLines[] = [
                'line_id' => (string) $item->id,
                'account_id' => $item->revenue_account_id,
                'amount' => (float) $item->line_total,
                'description' => $item->description,
            ];

            $taxAmount = round(((float) $item->line_total * (float) $item->tax_rate) / 100.0, 2);
            $taxLines[] = [
                'line_id' => (string) $item->id,
                'account_id' => $item->tax_account_id,
                'tax_rate' => (float) $item->tax_rate,
                'amount' => $taxAmount,
            ];
        }

        $invoice->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $userId,
        ]);

        // 1. Record customer ledger entry
        $this->ledgerService->recordInvoiceEntry($invoice, $userId);

        // 2. Dispatch domain event
        $this->eventDispatcher->dispatch(new InvoicePostedEvent(
            invoiceId: (string) $invoice->id,
            invoiceNumber: (string) $invoice->invoice_number,
            customerId: (string) $invoice->contact_id,
            date: (string) $invoice->date,
            dueDate: $invoice->due_date ? (string) $invoice->due_date : null,
            currency: (string) $invoice->currency,
            exchangeRate: (float) $invoice->exchange_rate,
            totalNet: (float) $invoice->total_net,
            totalTax: (float) $invoice->total_tax,
            totalGross: (float) $invoice->total_gross,
            revenueLines: $revenueLines,
            taxLines: $taxLines,
            glJournalEntryId: $invoice->gl_journal_entry_id,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        // 3. Emit ReceivableReadyEvent for Treasury integration
        $this->eventDispatcher->dispatch(new ReceivableReadyEvent(
            receivableId: (string) $invoice->id,
            customerId: (string) $invoice->contact_id,
            invoiceNumber: (string) $invoice->invoice_number,
            totalAmount: (float) $invoice->total_gross,
            dueDate: (string) ($invoice->due_date ?? now()->toDateString()),
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $invoice;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $items
     */
    public function issueCreditNote(SalesInvoice $originalInvoice, array $data, array $items): SalesInvoice
    {
        $tenantId = (string) $originalInvoice->tenant_id;
        $creditNoteId = (string) Str::uuid7();
        $creditNumber = $data['invoice_number'] ?? 'CN-'.date('Y').'-'.strtoupper(Str::random(6));

        $totals = $this->pricingService->calculateOrderTotals($items);

        $creditNote = SalesInvoice::create([
            'id' => $creditNoteId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $originalInvoice->contact_id,
            'invoice_type' => 'credit_note',
            'status' => 'posted',
            'invoice_number' => $creditNumber,
            'related_invoice_id' => (string) $originalInvoice->id,
            'date' => $data['date'] ?? now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => $originalInvoice->currency ?? 'EUR',
            'exchange_rate' => (float) ($originalInvoice->exchange_rate ?? 1.0),
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_total'],
            'total_net' => $totals['net_total'],
            'total_tax' => $totals['tax_total'],
            'total_gross' => $totals['gross_total'],
            'receivable_amount' => 0.0,
            'total_paid' => $totals['gross_total'],
            'payment_status' => 'paid',
            'posted_at' => now(),
            'posted_by' => $data['created_by'] ?? null,
            'notes' => $data['notes'] ?? 'Credit note for invoice '.$originalInvoice->invoice_number,
            'created_by' => $data['created_by'] ?? null,
        ]);

        foreach ($items as $item) {
            SalesInvoiceItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'invoice_id' => $creditNoteId,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Credit item',
                'quantity' => (float) ($item['quantity'] ?? 1.0),
                'unit_price' => (float) ($item['unit_price'] ?? 0.0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 22.0),
                'discount_percentage' => (float) ($item['discount_percentage'] ?? 0.0),
                'line_total' => (float) ($item['line_total'] ?? 0.0),
            ]);
        }

        // Record customer ledger credit entry
        $this->ledgerService->recordCreditNoteEntry($creditNote, (string) ($data['created_by'] ?? 'system'));

        // Emit CreditNoteIssuedEvent
        $this->eventDispatcher->dispatch(new CreditNoteIssuedEvent(
            creditNoteId: $creditNoteId,
            creditNoteNumber: $creditNumber,
            originalInvoiceId: (string) $originalInvoice->id,
            customerId: (string) $originalInvoice->contact_id,
            creditedAmount: (float) $creditNote->total_gross,
            adjustmentLines: $items,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $creditNote->load('items');
    }

    public function applyCreditNote(SalesInvoice $creditNote, SalesInvoice $targetInvoice, float $amount, string $userId): InvoiceCreditApplication
    {
        $application = InvoiceCreditApplication::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $creditNote->tenant_id,
            'credit_note_id' => (string) $creditNote->id,
            'original_invoice_id' => (string) $targetInvoice->id,
            'applied_amount' => $amount,
            'application_date' => now()->toDateString(),
            'notes' => 'Credit application from '.$creditNote->invoice_number.' to '.$targetInvoice->invoice_number,
            'applied_by' => $userId,
        ]);

        $newPaid = (float) $targetInvoice->total_paid + $amount;
        $isFullyPaid = $newPaid >= (float) $targetInvoice->total_gross;

        $targetInvoice->update([
            'total_paid' => $newPaid,
            'payment_status' => $isFullyPaid ? 'paid' : 'partially_paid',
        ]);

        return $application;
    }
}
