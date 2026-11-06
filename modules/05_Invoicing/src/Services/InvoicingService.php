<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Services\Sales\DocumentNumberingService;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
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
    private const string DRAFT_PREFIX = 'DRAFT-';

    public function __construct(
        private readonly PricingAndDiscountServiceInterface $pricingService,
        private readonly CustomerLedgerServiceInterface $ledgerService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DocumentNumberingService $numbering
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createInvoice(array $data, array $items): SalesInvoice
    {
        $tenantId = (string) $data['tenant_id'];
        $invoiceId = (string) Str::uuid7();

        $this->assertTaxRates($items);
        $totals = $this->pricingService->calculateOrderTotals($items);

        // The fiscal number is assigned at emission: a draft only carries a placeholder.
        $invoiceNumber = $data['invoice_number'] ?? self::DRAFT_PREFIX.strtoupper(Str::random(12));

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
            'created_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : null,
        ]);

        $actualInvoiceId = (string) $invoice->id;

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1.0);
            $unitPrice = (float) ($item['unit_price'] ?? 0.0);
            $taxRate = (float) $item['tax_rate'];
            $discountPct = (float) ($item['discount_percentage'] ?? 0.0);
            $lineNet = round(($qty * $unitPrice) * (1 - ($discountPct / 100.0)), 2);

            SalesInvoiceItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'invoice_id' => $actualInvoiceId,
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

        $this->dispatchDomainEvent(new InvoiceDraftedEvent(
            invoiceId: $actualInvoiceId,
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
        string $tenantId,
        ?float $taxRate = null
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
                'tax_rate' => $taxRate,
                'discount_percentage' => 0.0,
            ],
        ];

        return $this->createInvoice($data, $items);
    }

    public function approveInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        if ($invoice->status !== 'draft') {
            throw new LedgerPostingException("Invoice in status [{$invoice->status}] cannot be approved.");
        }

        $invoice->update([
            'status' => 'approved',
        ]);

        $this->dispatchDomainEvent(new InvoiceApprovedEvent(
            invoiceId: (string) $invoice->id,
            approvedBy: $userId,
            tenantId: (string) $invoice->tenant_id,
            companyId: (string) $invoice->tenant_id
        ));

        return $invoice;
    }

    public function postInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        $posted = DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $locked = SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'posted') {
                return $locked->load('items');
            }

            if ($locked->invoice_type === 'proforma') {
                throw new LedgerPostingException('A proforma is not a fiscal document and cannot be posted.');
            }

            if (! in_array($locked->status, ['draft', 'approved'], true)) {
                throw new LedgerPostingException("Invoice in status [{$locked->status}] cannot be posted.");
            }

            return $locked->isCreditNote() ? $this->postCreditNote($locked, $userId) : $this->postLockedInvoice($locked, $userId);
        });

        $invoice->setRawAttributes($posted->getAttributes(), true);

        return $invoice->setRelation('items', $posted->items);
    }

    /**
     * Assigns the fiscal number of a draft from the sequence of its year and series. It runs in the transaction of the
     * emission: when the emission fails, the number goes back and the series keeps no gaps.
     */
    private function assignFiscalNumber(SalesInvoice $invoice, string $series): void
    {
        if (! str_starts_with((string) $invoice->invoice_number, self::DRAFT_PREFIX)) {
            return;
        }

        $number = $this->numbering->next($series, CarbonImmutable::parse($invoice->date)->year, (string) $invoice->tenant_id);

        $invoice->invoice_number = $number['number'];
        $invoice->fiscal_year = $number['fiscal_year'];
        $invoice->sequential_number = $number['sequential_number'];
    }

    /**
     * Swiss francs are settled in steps of 5 centimes: the emission fixes the amount the QR-bill and the ledger share.
     */
    private function applyCashRounding(SalesInvoice $invoice): void
    {
        if ($invoice->currency !== 'CHF' || (float) $invoice->rounding_amount !== 0.0) {
            return;
        }

        $exact = (float) $invoice->total_gross;
        $rounded = round(round($exact / 0.05) * 0.05, 2);

        $invoice->total_gross = $rounded;
        $invoice->rounding_amount = round($rounded - $exact, 2);
        $invoice->receivable_amount = $rounded;
    }

    private function postLockedInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
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

        $postedUserId = Str::isUuid($userId) ? $userId : null;

        $this->assignFiscalNumber($invoice, 'invoice');
        $this->applyCashRounding($invoice);

        $invoice->status = 'posted';
        $invoice->posted_at = now();
        $invoice->posted_by = $postedUserId;
        $invoice->save();

        // 1. Record customer ledger entry
        $ledgerEntry = $this->ledgerService->recordInvoiceEntry($invoice, (string) $postedUserId);

        // 2. Dispatch domain event
        $this->dispatchDomainEvent(new InvoicePostedEvent(
            invoiceId: (string) $invoice->id,
            invoiceNumber: (string) $invoice->invoice_number,
            customerId: (string) $invoice->contact_id,
            date: CarbonImmutable::parse($invoice->date)->toDateString(),
            dueDate: $invoice->due_date ? CarbonImmutable::parse($invoice->due_date)->toDateString() : null,
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

        $invoice->refresh();

        // 3. Emit ReceivableReadyEvent for Treasury integration, unless the ledger posting already announced the item
        if ($invoice->gl_journal_entry_id === null) {
            $this->dispatchDomainEvent(new ReceivableReadyEvent(
                customerLedgerId: (string) $ledgerEntry->id,
                customerId: (string) $invoice->contact_id,
                openAmount: (float) $invoice->total_gross,
                currency: (string) ($invoice->currency ?? 'EUR'),
                dueDate: CarbonImmutable::parse($invoice->due_date ?? now())->toDateString(),
                journalEntryId: '',
                tenantId: $tenantId,
                companyId: $tenantId
            ));
        }

        return $invoice->load('items');
    }

    /**
     * Emission of a credit note stored as a draft by the legacy sales flow.
     */
    private function postCreditNote(SalesInvoice $creditNote, string $userId): SalesInvoice
    {
        $creditNote->load('items');
        $this->assignFiscalNumber($creditNote, 'credit_note');
        $this->applyCashRounding($creditNote);

        $creditNote->status = 'posted';
        $creditNote->posted_at = now();
        $creditNote->posted_by = Str::isUuid($userId) ? $userId : null;
        $creditNote->total_paid = $creditNote->total_gross;
        $creditNote->payment_status = 'paid';
        $creditNote->receivable_amount = 0.0;
        $creditNote->save();

        $original = $creditNote->related_invoice_id === null ? null : SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($creditNote->related_invoice_id)->lockForUpdate()->first();

        $this->completeCreditNote($creditNote, $original, $creditNote->items->map(static fn ($item): array => [
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'tax_rate' => $item->tax_rate,
            'discount_percentage' => $item->discount_percentage,
        ])->all(), $userId);

        if ($original !== null) {
            $outstanding = round((float) $original->total_gross - (float) $original->total_paid, 2);
            $credited = min($outstanding, (float) $creditNote->total_gross);

            if ($credited > 0) {
                $this->applyCreditNote($creditNote, $original, $credited, $userId);
            }
        }

        return $creditNote->refresh()->load('items');
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function completeCreditNote(SalesInvoice $creditNote, ?SalesInvoice $original, array $items, string $userId): void
    {
        $this->ledgerService->recordCreditNoteEntry($creditNote, $userId);

        $this->dispatchDomainEvent(new CreditNoteIssuedEvent(
            creditNoteId: (string) $creditNote->id,
            creditNoteNumber: (string) $creditNote->invoice_number,
            originalInvoiceId: (string) ($original?->id ?? ''),
            customerId: (string) $creditNote->contact_id,
            creditedAmount: (float) $creditNote->total_gross,
            adjustmentLines: $items,
            tenantId: (string) $creditNote->tenant_id,
            companyId: (string) $creditNote->tenant_id
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertTaxRates(array $items): void
    {
        foreach ($items as $item) {
            if (! isset($item['tax_rate'])) {
                throw new LedgerPostingException('Every invoice line must state its VAT rate.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function issueCreditNote(SalesInvoice $originalInvoice, array $data, array $items): SalesInvoice
    {
        return DB::transaction(fn (): SalesInvoice => $this->createPostedCreditNote($originalInvoice, $data, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createPostedCreditNote(SalesInvoice $originalInvoice, array $data, array $items): SalesInvoice
    {
        $tenantId = (string) $originalInvoice->tenant_id;
        $creditNoteId = (string) Str::uuid7();
        $date = (string) ($data['date'] ?? now()->toDateString());
        $number = isset($data['invoice_number']) ? null : $this->numbering->next('credit_note', CarbonImmutable::parse($date)->year, $tenantId);
        $creditNumber = $data['invoice_number'] ?? $number['number'];

        $this->assertTaxRates($items);
        $totals = $this->pricingService->calculateOrderTotals($items);

        $creditNote = SalesInvoice::create([
            'id' => $creditNoteId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $originalInvoice->contact_id,
            'invoice_type' => 'credit_note',
            'status' => 'posted',
            'invoice_number' => $creditNumber,
            'fiscal_year' => $number['fiscal_year'] ?? null,
            'sequential_number' => $number['sequential_number'] ?? null,
            'related_invoice_id' => (string) $originalInvoice->id,
            'date' => $date,
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
            'posted_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : (string) Str::uuid7(),
            'notes' => $data['notes'] ?? 'Credit note for invoice '.$originalInvoice->invoice_number,
            'created_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : (string) Str::uuid7(),
        ]);

        $actualCreditNoteId = (string) $creditNote->id;

        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 1.0);
            $unitPrice = (float) ($item['unit_price'] ?? 0.0);
            $discount = (float) ($item['discount_percentage'] ?? 0.0);

            SalesInvoiceItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'invoice_id' => $actualCreditNoteId,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Credit item',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => (float) $item['tax_rate'],
                'discount_percentage' => $discount,
                'line_total' => round($quantity * $unitPrice * (1 - $discount / 100.0), 2),
            ]);
        }

        $this->completeCreditNote($creditNote, $originalInvoice, $items, (string) ($data['created_by'] ?? 'system'));

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
            'applied_by' => Str::isUuid($userId) ? $userId : (string) Str::uuid7(),
        ]);

        $newPaid = (float) $targetInvoice->total_paid + $amount;
        $isFullyPaid = $newPaid >= (float) $targetInvoice->total_gross;

        $targetInvoice->update([
            'total_paid' => $newPaid,
            'payment_status' => $isFullyPaid ? 'paid' : 'partially_paid',
        ]);

        return $application;
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publish($event);
        $this->eventDispatcher->dispatch($event);
    }
}
