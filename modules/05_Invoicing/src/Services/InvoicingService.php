<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Tenant;
use App\Services\ItalianSdi\InvoiceFiscalService;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Sales\VatSummaryCalculator;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Events\ReceivableReadyEvent;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Services\ProductChargeCalculator;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\InvoiceApprovedEvent;
use Modules\Invoicing\Events\InvoiceDraftedEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\BillingSchedule;
use Modules\Invoicing\Models\InvoiceCreditApplication;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\SDI\Enums\TipoDocumento;
use RoundingMode;

/**
 * The single writer of the sales documents of the `invoices` table: invoices, credit and debit notes, proformas and
 * down payments of every flow (the sales API of the app, the invoicing module, subscriptions, projects, service reports)
 * are created and issued here. A draft gets a placeholder number; the emission assigns the gapless fiscal number,
 * freezes the exchange rate, applies the Italian fiscal rules and the cash rounding, fixes the payment schedule and
 * posts the ledger entry, all in one transaction: a refused emission (closed period, missing rate, plafond exhausted)
 * leaves neither the document nor a gap in the numbering. Amounts are computed in decimal arithmetic.
 */
final class InvoicingService implements InvoicingServiceInterface
{
    private const string DRAFT_PREFIX = 'DRAFT-';

    /** Key of the pension fund contribution among the VAT entries of a document. */
    private const string FUND_VAT_ENTRY = 'fund';

    /**
     * What a caller may state when an invoice is generated from a sales order or a delivery note. Everything else (the
     * customer, the source documents, the number, the lines) comes from the source document and the numbering service.
     *
     * @var list<string>
     */
    public const array SOURCE_OVERRIDE_FIELDS = ['date', 'due_date', 'notes', 'payment_method', 'payment_terms', 'bank_name', 'bank_iban', 'created_by'];

    /**
     * Links of a document to its origin that a flow may state on creation, besides the source documents.
     *
     * @var list<string>
     */
    private const array LINK_FIELDS = ['quote_id', 'proforma_id', 'subscription_id', 'related_invoice_id', 'payment_term_id', 'vat_payability'];

    private readonly SalesBillingLines $billingLines;

    private readonly InvoiceExchangeRateResolver $exchangeRates;

    private readonly InvoiceInstallmentService $installments;

    public function __construct(
        private readonly CustomerLedgerServiceInterface $ledgerService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DocumentNumberingService $numbering,
        private readonly InvoiceFiscalService $fiscal,
        private readonly DeclarationOfIntentService $declarations,
        private readonly ProductChargeCalculator $charges = new ProductChargeCalculator,
        private readonly VatSummaryCalculator $vat = new VatSummaryCalculator,
        private readonly ?TenantClock $clock = null,
        private readonly DocumentTotalsCalculator $totals = new DocumentTotalsCalculator,
        ?SalesBillingLines $billingLines = null,
        ?InvoiceExchangeRateResolver $exchangeRates = null,
        ?InvoiceInstallmentService $installments = null,
    ) {
        $this->billingLines = $billingLines ?? app(SalesBillingLines::class);
        $this->exchangeRates = $exchangeRates ?? app(InvoiceExchangeRateResolver::class);
        $this->installments = $installments ?? app(InvoiceInstallmentService::class);
    }

    /**
     * Creates a draft sales document from its header and lines. The lines and totals are computed in decimal arithmetic
     * (VAT per rate on the summed net, like the summary of the electronic invoice); a proforma is numbered from its own
     * series at once, any other document gets its fiscal number at emission. Lines linked to sales order lines bill
     * those lines (their invoiced quantity and the billing status of the order follow).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createInvoice(array $data, array $items): SalesInvoice
    {
        return DB::transaction(fn (): SalesInvoice => $this->createDraft($data, $items));
    }

    /**
     * Creates and issues a sales document in one transaction (see createInvoice and postInvoice).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function issueInvoice(array $data, array $items, string $userId): SalesInvoice
    {
        return DB::transaction(fn (): SalesInvoice => $this->postInvoice($this->createDraft($data, $items), $userId));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createDraft(array $data, array $items): SalesInvoice
    {
        $tenantId = (string) $data['tenant_id'];
        $invoiceId = (string) Str::uuid7();
        $today = $this->today($tenantId);
        $data['date'] ??= $today->toDateString();
        $invoiceType = (string) ($data['invoice_type'] ?? 'standard');

        $this->assertTaxRates($items);

        if (empty($data['skip_charge_lines'])) {
            $items = [...$items, ...$this->chargeLines($data, $items, $tenantId)];
        }

        $computed = $this->totals->calculateDecimal($items);
        [$invoiceNumber, $fiscalYear, $sequential] = $this->draftNumber($data, $invoiceType, $tenantId);
        $statedRate = isset($data['exchange_rate']) && $data['exchange_rate'] !== '' ? DocumentTotalsCalculator::decimal($data['exchange_rate']) : null;

        $invoice = SalesInvoice::create([
            'id' => $invoiceId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $data['contact_id'],
            'invoice_type' => $invoiceType,
            'status' => InvoiceStatus::Draft->value,
            'invoice_number' => $invoiceNumber,
            'fiscal_year' => $fiscalYear,
            'sequential_number' => $sequential,
            'date' => $data['date'],
            'due_date' => array_key_exists('due_date', $data) ? $data['due_date'] : $today->addDays(30)->toDateString(),
            'currency' => strtoupper((string) ($data['currency'] ?? 'EUR')),
            'exchange_rate' => $statedRate ?? '1',
            'exchange_rate_source' => $statedRate !== null ? InvoiceExchangeRateResolver::SOURCE_DOCUMENT : null,
            'subtotal' => $computed['subtotal'],
            'discount_amount' => $computed['discount_total'],
            'total_net' => $computed['total_net'],
            'total_tax' => $computed['total_tax'],
            'total_gross' => $computed['total_gross'],
            'receivable_amount' => $computed['total_gross'],
            'total_paid' => '0',
            'payment_status' => 'unpaid',
            'jurisdiction' => $data['jurisdiction'] ?? 'IT',
            'split_payment' => (bool) ($data['split_payment'] ?? false),
            'reverse_charge' => (bool) ($data['reverse_charge'] ?? false),
            'sdi_excluded' => (bool) ($data['sdi_excluded'] ?? false),
            'is_deposit' => (bool) ($data['is_deposit'] ?? false),
            'payment_method' => array_key_exists('payment_method', $data) ? $data['payment_method'] : 'MP05',
            'payment_terms' => array_key_exists('payment_terms', $data) ? $data['payment_terms'] : 'TP02',
            'bank_name' => $data['bank_name'] ?? null,
            'bank_iban' => $data['bank_iban'] ?? null,
            'sales_order_id' => $data['sales_order_id'] ?? null,
            'delivery_note_id' => $data['delivery_note_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'project_milestone_id' => $data['project_milestone_id'] ?? null,
            'customer_agreement_id' => $data['customer_agreement_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : null,
            ...array_filter(Arr::only($data, self::LINK_FIELDS), static fn (mixed $value): bool => $value !== null),
            ...Arr::only($data, InvoiceFiscalService::INPUT_FIELDS),
        ]);

        $actualInvoiceId = (string) $invoice->id;

        foreach ($computed['items'] as $index => $line) {
            $item = $items[$index];

            SalesInvoiceItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'invoice_id' => $actualInvoiceId,
                'sales_order_item_id' => $item['sales_order_item_id'] ?? null,
                'delivery_note_item_id' => $item['delivery_note_item_id'] ?? null,
                'deducted_invoice_item_id' => $item['deducted_invoice_item_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Invoice item',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
                'discount_percentage' => $line['discount_percentage'],
                'line_total' => $line['line_total'],
                'revenue_account_id' => $item['revenue_account_id'] ?? null,
                'tax_account_id' => $item['tax_account_id'] ?? null,
                'cost_center_id' => $item['cost_center_id'] ?? null,
                'product_charge_type_id' => $item['product_charge_type_id'] ?? null,
                'uom' => $item['uom'] ?? 'EA',
                'notes' => $item['notes'] ?? null,
            ]);
        }

        if ($invoiceType !== 'proforma' && $invoiceType !== 'credit_note') {
            $this->billingLines->recordBilledQuantities($items);
        }

        $this->fiscal->apply($invoice);
        $invoice->save();

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

    /**
     * The number of a new draft: the register number of a document outside SDI, the next proforma number of a proforma,
     * else a placeholder replaced by the fiscal number at emission.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int|null, 2: int|null}
     */
    private function draftNumber(array $data, string $invoiceType, string $tenantId): array
    {
        $own = $this->ownDocumentNumber($data);

        if ($own !== null) {
            return [$own, null, null];
        }

        if ($invoiceType === 'proforma') {
            $number = $this->numbering->next('proforma', CarbonImmutable::parse((string) $data['date'])->year, $tenantId);

            return [$number['number'], $number['fiscal_year'], $number['sequential_number']];
        }

        return [self::DRAFT_PREFIX.strtoupper(Str::random(12)), null, null];
    }

    /**
     * Invoices what is left to invoice on a sales order, or only the requested quantities of some lines
     * (`$overrides['lines']`: list of sales_order_item_id and quantity). The order row is locked for the whole
     * operation, so two concurrent requests cannot bill the same quantities. The invoice that closes the order deducts
     * what is left of its down payments. An order billed by an active billing plan is not billed by quantity.
     *
     * @param  array<string, mixed>  $overrides  the keys in SOURCE_OVERRIDE_FIELDS and `lines`
     *
     * @throws ValidationException when the order is cancelled, already billed, billed by a plan or has nothing left
     */
    public function createInvoiceFromOrder(SalesOrder $order, array $overrides = []): SalesInvoice
    {
        return DB::transaction(function () use ($order, $overrides): SalesInvoice {
            $locked = SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [SalesOrderStatus::Cancelled->value, SalesOrderStatus::Rejected->value], true)) {
                throw ValidationException::withMessages(['sales_order' => __('sales.order_cancelled')]);
            }

            if ($locked->billing_status === SalesBillingLines::STATUS_BILLED) {
                throw ValidationException::withMessages(['sales_order' => __('sales.order_already_invoiced')]);
            }

            if (BillingSchedule::withoutGlobalScope(TenantScope::class)->where('sales_order_id', $locked->id)->where('status', OrderBillingService::PLAN_ACTIVE)->exists()) {
                throw ValidationException::withMessages(['sales_order' => __('sales.order_billed_by_plan')]);
            }

            $invoice = $this->invoiceLockedOrder($locked, $overrides);
            $order->setRawAttributes($locked->fresh()?->getAttributes() ?? $locked->getAttributes(), true);

            return $invoice;
        });
    }

    /**
     * Invoices a sales order (see createInvoiceFromOrder) and issues the invoice in the same transaction.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function issueInvoiceFromOrder(SalesOrder $order, array $overrides, string $userId): SalesInvoice
    {
        return DB::transaction(fn (): SalesInvoice => $this->postInvoice($this->createInvoiceFromOrder($order, $overrides), $userId));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoiceLockedOrder(SalesOrder $order, array $overrides): SalesInvoice
    {
        $order->load('items');
        $tenantId = (string) $order->tenant_id;
        $today = $this->today($tenantId);
        $requested = isset($overrides['lines']) && is_array($overrides['lines']) ? array_values($overrides['lines']) : null;
        $items = $this->billingLines->openOrderLines($order, $requested);

        if ($items === []) {
            throw ValidationException::withMessages(['sales_order' => __('sales.nothing_to_invoice')]);
        }

        if ($this->billingLines->closesOrder($order, $items)) {
            $items = [...$items, ...$this->billingLines->downPaymentDeductions($order)];
        }

        $data = array_merge([
            'currency' => $order->currency ?? 'EUR',
            'date' => $today->toDateString(),
            'due_date' => $today->addDays(30)->toDateString(),
            'notes' => 'Generated from Sales Order '.$order->order_number,
        ], $this->sourceOverrides($overrides), [
            'tenant_id' => $tenantId,
            'contact_id' => (string) $order->contact_id,
            'sales_order_id' => (string) $order->id,
            'quote_id' => $overrides['quote_id'] ?? null,
        ]);

        return $this->createDraft($data, $items);
    }

    /**
     * Invoices a delivery note, once: the note row is locked for the whole operation. Lines without a price take it
     * from their order line; the order lines are billed by the invoiced quantities.
     *
     * @param  array<string, mixed>  $overrides  only the keys in SOURCE_OVERRIDE_FIELDS are used
     *
     * @throws ValidationException when the note is already invoiced or has no lines
     */
    public function createInvoiceFromDeliveryNote(SalesDeliveryNote $deliveryNote, array $overrides = []): SalesInvoice
    {
        return DB::transaction(function () use ($deliveryNote, $overrides): SalesInvoice {
            $locked = SalesDeliveryNote::withoutGlobalScope(TenantScope::class)->whereKey($deliveryNote->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === DeliveryNoteStatus::Invoiced->value || $this->billingLines->deliveryNoteBilled($locked)) {
                throw ValidationException::withMessages(['delivery_note' => __('sales.delivery_note_already_invoiced')]);
            }

            $invoice = $this->invoiceLockedDeliveryNote($locked, $overrides);
            $deliveryNote->setRawAttributes($locked->getAttributes(), true);

            return $invoice;
        });
    }

    /**
     * Invoices a delivery note (see createInvoiceFromDeliveryNote) and issues the invoice in the same transaction.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function issueInvoiceFromDeliveryNote(SalesDeliveryNote $deliveryNote, array $overrides, string $userId): SalesInvoice
    {
        return DB::transaction(fn (): SalesInvoice => $this->postInvoice($this->createInvoiceFromDeliveryNote($deliveryNote, $overrides), $userId));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoiceLockedDeliveryNote(SalesDeliveryNote $deliveryNote, array $overrides): SalesInvoice
    {
        $tenantId = (string) $deliveryNote->tenant_id;
        $today = $this->today($tenantId);
        $items = $this->billingLines->deliveryNoteLines($deliveryNote);

        if ($items === []) {
            throw ValidationException::withMessages(['delivery_note' => __('sales.nothing_to_invoice')]);
        }

        $currency = $deliveryNote->sales_order_id !== null
            ? SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($deliveryNote->sales_order_id)->value('currency')
            : null;

        $data = array_merge([
            'date' => $today->toDateString(),
            'due_date' => $today->addDays(30)->toDateString(),
            'notes' => 'Generated from Delivery Note '.$deliveryNote->ddt_number,
        ], $currency !== null ? ['currency' => $currency] : [], $this->sourceOverrides($overrides), [
            'tenant_id' => $tenantId,
            'contact_id' => (string) $deliveryNote->contact_id,
            'sales_order_id' => $deliveryNote->sales_order_id,
            'delivery_note_id' => (string) $deliveryNote->id,
        ]);

        $invoice = $this->createDraft($data, $items);

        DeliveryNoteStatus::assertTransition($deliveryNote->status, DeliveryNoteStatus::Invoiced);
        SalesDeliveryNote::withoutGlobalScope(TenantScope::class)->whereKey($deliveryNote->id)->update(['status' => DeliveryNoteStatus::Invoiced->value]);
        $deliveryNote->status = DeliveryNoteStatus::Invoiced->value;

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
        $today = $this->today($tenantId);
        $data = [
            'tenant_id' => $tenantId,
            'contact_id' => $customerId,
            'project_id' => $projectId,
            'project_milestone_id' => $milestoneId,
            'date' => $today->toDateString(),
            'due_date' => $today->addDays(30)->toDateString(),
            'notes' => 'Project Milestone Billing: '.$description,
        ];

        $items = [
            [
                'description' => $description,
                'quantity' => '1',
                'unit_price' => DocumentTotalsCalculator::decimal($amount),
                'tax_rate' => $taxRate,
                'discount_percentage' => '0',
            ],
        ];

        return $this->createInvoice($data, $items);
    }

    /**
     * Approves a draft. The row is locked, so an approval and an emission cannot cross.
     *
     * @throws LedgerPostingException when the document is not a draft
     */
    public function approveInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        $approved = DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $locked = SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Draft->value || ! InvoiceStatus::allows($locked->status, InvoiceStatus::Approved)) {
                throw new LedgerPostingException(__('invoicing_errors.invoice.cannot_be_approved', ['status' => $locked->status]));
            }

            $locked->status = InvoiceStatus::Approved->value;
            $locked->save();

            $this->dispatchDomainEvent(new InvoiceApprovedEvent(
                invoiceId: (string) $locked->id,
                approvedBy: $userId,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) $locked->tenant_id
            ));

            return $locked;
        });

        $invoice->setRawAttributes($approved->getAttributes(), true);

        return $invoice;
    }

    /**
     * Issues a draft or approved document (see the class description). Posting an issued document again returns it.
     *
     * @throws LedgerPostingException when the document is a proforma or in a status that cannot be posted
     */
    public function postInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        $posted = DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $locked = SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceStatus::Posted->value) {
                return $locked->load('items');
            }

            if ($locked->invoice_type === 'proforma') {
                throw new LedgerPostingException(__('invoicing_errors.invoice.proforma_not_postable'));
            }

            if (! InvoiceStatus::allows($locked->status, InvoiceStatus::Posted)) {
                throw new LedgerPostingException(__('invoicing_errors.invoice.cannot_be_posted', ['status' => $locked->status]));
            }

            return $locked->isCreditNote() ? $this->postCreditNote($locked, $userId) : $this->postLockedInvoice($locked, $userId);
        });

        $invoice->setRawAttributes($posted->getAttributes(), true);

        return $invoice->setRelation('items', $posted->items);
    }

    /**
     * Issues a debit note (TD05) adjusting an issued invoice upwards: same customer, currency, exchange rate,
     * jurisdiction and VAT regime as the invoice, linked to it (DatiFattureCollegate), posted as a receivable.
     *
     * @param  array<string, mixed>  $data  date, due_date, notes
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws ValidationException when the original is not an issued invoice
     */
    public function issueDebitNote(SalesInvoice $originalInvoice, array $data, array $items, string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($originalInvoice, $data, $items, $userId): SalesInvoice {
            $original = SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($originalInvoice->id)->lockForUpdate()->firstOrFail();
            $type = TipoDocumento::ofInvoice($original);

            if ($original->status !== InvoiceStatus::Posted->value || $original->invoice_type === 'proforma' || $original->isCreditNote() || $type->isSelfInvoice() || $type === TipoDocumento::Td05) {
                throw ValidationException::withMessages(['original_invoice_id' => __('sales.debit_note_original_not_issued')]);
            }

            return $this->postInvoice($this->createDraft([
                'tenant_id' => (string) $original->tenant_id,
                'contact_id' => (string) $original->contact_id,
                'invoice_type' => 'standard',
                'document_type' => TipoDocumento::Td05->value,
                'related_invoice_id' => (string) $original->id,
                'date' => $data['date'] ?? null,
                ...(array_key_exists('due_date', $data) && $data['due_date'] !== null ? ['due_date' => $data['due_date']] : []),
                'currency' => $original->currency,
                'exchange_rate' => (string) $original->exchange_rate,
                'jurisdiction' => $original->jurisdiction,
                'split_payment' => (bool) $original->split_payment,
                'reverse_charge' => (bool) $original->reverse_charge,
                'sdi_excluded' => (bool) $original->sdi_excluded,
                'payment_method' => $original->payment_method,
                'payment_terms' => $original->payment_terms,
                'bank_name' => $original->bank_name,
                'bank_iban' => $original->bank_iban,
                'notes' => $data['notes'] ?? 'Debit note for invoice '.$original->invoice_number,
                'skip_charge_lines' => true,
                'created_by' => $userId,
            ], $items), $userId);
        });
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
     * What is settled is the amount due, not the document total: with a withholding (or Enasarco, or split payment)
     * the customer pays less than the gross, so that amount is the one rounded, and the difference goes on the gross,
     * which keeps the withheld amounts exact. Without such deductions the two are the same.
     */
    private function applyCashRounding(SalesInvoice $invoice): void
    {
        if ($invoice->currency !== 'CHF' || ! Decimal::isZero((string) ($invoice->rounding_amount ?? '0'))) {
            return;
        }

        $exactDue = $invoice->amountDue();
        $steps = bcround(Decimal::div($exactDue, '0.05'), 0, RoundingMode::HalfAwayFromZero);
        $roundedDue = Decimal::round2(Decimal::mul($steps, '0.05'));
        $rounding = Decimal::round2(Decimal::sub($roundedDue, $exactDue));

        $invoice->total_gross = Decimal::round2(Decimal::add((string) $invoice->total_gross, $rounding));
        $invoice->rounding_amount = $rounding;
        $invoice->receivable_amount = $roundedDue;
    }

    private function postLockedInvoice(SalesInvoice $invoice, string $userId): SalesInvoice
    {
        $invoice->load('items');
        $tenantId = (string) $invoice->tenant_id;

        $this->fiscal->apply($invoice, atIssue: true);
        $this->fiscal->assertIssuable($invoice);
        $this->exchangeRates->freeze($invoice);

        if ($invoice->declaration_of_intent_id !== null) {
            $this->declarations->consume($invoice);
        }

        $revenueLines = [];
        $taxLines = [];
        $lineTaxes = $this->vat->allocate($this->vatEntries($invoice));

        foreach ($invoice->items as $item) {
            $revenueLine = [
                'line_id' => (string) $item->id,
                'account_id' => $item->revenue_account_id,
                'amount' => (string) $item->line_total,
                'description' => $item->description,
            ];

            if ($item->cost_center_id !== null) {
                $revenueLine['cost_center_id'] = (string) $item->cost_center_id;
            }

            $revenueLines[] = $revenueLine;

            // The tax of each rate is computed on its summed net and then spread over its lines, so the lines add up to
            // the stored total tax and to the Imposta of the XML summary.
            $taxLines[] = [
                'line_id' => (string) $item->id,
                'account_id' => $item->tax_account_id,
                'tax_rate' => (string) $item->tax_rate,
                'amount' => (string) $lineTaxes[(string) $item->id],
            ];
        }

        if ($invoice->fund_enabled && ! Decimal::isZero((string) ($invoice->fund_vat_amount ?? '0'))) {
            $taxLines[] = [
                'line_id' => null,
                'account_id' => null,
                'tax_rate' => (string) $invoice->fund_vat_rate,
                'amount' => (string) ($lineTaxes[self::FUND_VAT_ENTRY] ?? $invoice->fund_vat_amount),
                'base_amount' => (string) $invoice->fund_amount,
            ];
        }

        $postedUserId = Str::isUuid($userId) ? $userId : null;
        $documentType = TipoDocumento::ofInvoice($invoice);

        $this->assignFiscalNumber($invoice, $documentType->numberingSeries());
        $this->applyCashRounding($invoice);
        $this->installments->scheduleAtIssue($invoice);

        $invoice->status = InvoiceStatus::Posted->value;
        $invoice->posted_at = now();
        $invoice->posted_by = $postedUserId;
        $invoice->save();

        // 1. Record customer ledger entry: a self-invoice opens no item with the counterparty
        $ledgerEntry = $documentType->isSelfInvoice() ? null : $this->ledgerService->recordInvoiceEntry($invoice, (string) $postedUserId);

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
        if ($invoice->gl_journal_entry_id === null && $ledgerEntry !== null) {
            $this->dispatchDomainEvent(new ReceivableReadyEvent(
                customerLedgerId: (string) $ledgerEntry->id,
                customerId: (string) $invoice->contact_id,
                openAmount: (float) $invoice->amountDue(),
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
        $original = $creditNote->related_invoice_id === null ? null : SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($creditNote->related_invoice_id)->lockForUpdate()->first();
        $this->fiscal->applyToCreditNote($creditNote, $original);
        $this->fiscal->assertIssuable($creditNote);
        $this->inheritExchangeRate($creditNote, $original);
        $this->assignFiscalNumber($creditNote, 'credit_note');
        $this->applyCashRounding($creditNote);

        if ($original !== null) {
            $this->assertCreditWithinOriginal($original, $creditNote);
        }

        $creditNote->status = InvoiceStatus::Posted->value;
        $creditNote->posted_at = now();
        $creditNote->posted_by = Str::isUuid($userId) ? $userId : null;
        $creditNote->total_paid = $creditNote->total_gross;
        $creditNote->payment_status = 'paid';
        $creditNote->setAttribute('receivable_amount', '0');
        $creditNote->split_payment = $creditNote->split_payment || (bool) $original?->split_payment;
        $creditNote->save();

        $this->completeCreditNote($creditNote, $original, $creditNote->items->map(static fn ($item): array => [
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'tax_rate' => $item->tax_rate,
            'discount_percentage' => $item->discount_percentage,
        ])->all(), $userId);

        $settlesOriginal = $original !== null && $original->status === InvoiceStatus::Posted->value && ! $original->isCreditNote()
            && (string) $original->contact_id === (string) $creditNote->contact_id;

        if ($settlesOriginal) {
            $outstanding = $original->outstandingAmount();
            $creditable = $creditNote->amountDue();
            $credited = Decimal::compare($outstanding, $creditable) < 0 ? $outstanding : $creditable;

            if (Decimal::isPositive($credited)) {
                $this->applyCreditNote($creditNote, $original, $credited, $userId);
            }
        }

        return $creditNote->refresh()->load('items');
    }

    /**
     * A credit note takes the rate of the invoice it credits, so both reach the ledger at the same rate; without an
     * invoice its rate is frozen like any document's.
     */
    private function inheritExchangeRate(SalesInvoice $creditNote, ?SalesInvoice $original): void
    {
        if ($creditNote->exchange_rate_source !== null) {
            return;
        }

        if ($original !== null && $original->currency === $creditNote->currency) {
            $creditNote->setAttribute('exchange_rate', (string) $original->exchange_rate);
            $creditNote->exchange_rate_source = $original->exchange_rate_source ?? InvoiceExchangeRateResolver::SOURCE_DOCUMENT;
            $creditNote->exchange_rate_date = $original->exchange_rate_date;

            return;
        }

        $this->exchangeRates->freeze($creditNote);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function completeCreditNote(SalesInvoice $creditNote, ?SalesInvoice $original, array $items, string $userId): void
    {
        $this->ledgerService->recordCreditNoteEntry($creditNote, $userId);
        $this->declarations->release($creditNote, $original);

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
     * The supplementary charges of the products on the invoice, as lines of their own.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function chargeLines(array $data, array $items, string $tenantId): array
    {
        if (($data['invoice_type'] ?? 'standard') === 'proforma') {
            return [];
        }

        return $this->charges->linesFor($items, (string) ($data['date'] ?? $this->today($tenantId)->toDateString()), (string) ($data['currency'] ?? 'EUR'), $tenantId);
    }

    /**
     * The business day of the company on its own wall clock: document dates follow it, not the UTC date of the server.
     */
    private function today(string $tenantId): CarbonImmutable
    {
        $clock = $this->clock ?? app(TenantClock::class);

        return $clock->today(Tenant::query()->find($tenantId));
    }

    /**
     * A document outside SDI (an association receipt, say) keeps the number of its own register; every other document
     * takes its number from the gapless fiscal sequence, so a number stated by the caller is ignored.
     *
     * @param  array<string, mixed>  $data
     */
    private function ownDocumentNumber(array $data, bool $sdiExcluded = false): ?string
    {
        $number = $data['invoice_number'] ?? null;
        $isOutsideSdi = (bool) ($data['sdi_excluded'] ?? $sdiExcluded);

        return $isOutsideSdi && is_string($number) && $number !== '' && ! str_starts_with($number, self::DRAFT_PREFIX) ? $number : null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sourceOverrides(array $overrides): array
    {
        return array_filter(Arr::only($overrides, self::SOURCE_OVERRIDE_FIELDS), static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The VAT entries of a document (lines and pension fund contribution), keyed by line id.
     *
     * @return array<string, array{rate: string, taxable: string}>
     */
    private function vatEntries(SalesInvoice $invoice): array
    {
        $entries = [];

        foreach ($invoice->items as $item) {
            $entries[(string) $item->id] = ['rate' => (string) $item->tax_rate, 'taxable' => (string) $item->line_total];
        }

        if ($invoice->fund_enabled && Decimal::isPositive((string) ($invoice->fund_amount ?? '0'))) {
            $entries[self::FUND_VAT_ENTRY] = ['rate' => (string) ($invoice->fund_vat_rate ?? '0'), 'taxable' => (string) $invoice->fund_amount];
        }

        return $entries;
    }

    /**
     * Refuses a credit note that would take the credited total of its invoice above the invoice total.
     *
     * @throws ValidationException
     */
    private function assertCreditWithinOriginal(SalesInvoice $original, SalesInvoice $creditNote): void
    {
        $alreadyCredited = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('related_invoice_id', $original->id)
            ->where('invoice_type', 'credit_note')
            ->where('status', InvoiceStatus::Posted->value)
            ->whereKeyNot($creditNote->id)
            ->pluck('total_gross')
            ->reduce(static fn (string $sum, mixed $gross): string => Decimal::add($sum, DocumentTotalsCalculator::decimal($gross)), '0');

        $credited = Decimal::add($alreadyCredited, (string) $creditNote->total_gross);

        if (Decimal::compare($credited, (string) $original->total_gross) > 0) {
            throw ValidationException::withMessages(['items' => __('sales.credit_note_exceeds_invoice')]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function assertTaxRates(array $items): void
    {
        foreach ($items as $item) {
            if (! isset($item['tax_rate'])) {
                throw new LedgerPostingException(__('invoicing_errors.invoice.line_vat_rate_required'));
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
        // The original is locked for the whole emission: two credit notes on the same invoice are checked one at a time.
        $lockedOriginal = SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($originalInvoice->id)->lockForUpdate()->firstOrFail();

        if ($lockedOriginal->status !== InvoiceStatus::Posted->value || $lockedOriginal->invoice_type === 'proforma' || $lockedOriginal->isCreditNote()) {
            throw ValidationException::withMessages(['original_invoice_id' => __('sales.credit_note_original_not_issued')]);
        }

        $originalInvoice = $lockedOriginal;
        $tenantId = (string) $originalInvoice->tenant_id;
        $creditNoteId = (string) Str::uuid7();
        $today = $this->today($tenantId);
        $date = (string) ($data['date'] ?? $today->toDateString());
        $sdiExcluded = (bool) ($data['sdi_excluded'] ?? $originalInvoice->sdi_excluded);
        $ownNumber = $this->ownDocumentNumber($data, $sdiExcluded);
        $number = $ownNumber === null ? $this->numbering->next('credit_note', CarbonImmutable::parse($date)->year, $tenantId) : null;
        $creditNumber = $ownNumber ?? $number['number'];
        $actorId = ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : null;

        $this->assertTaxRates($items);
        $computed = $this->totals->calculateDecimal($items);

        $creditNote = SalesInvoice::create([
            'id' => $creditNoteId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $originalInvoice->contact_id,
            'invoice_type' => 'credit_note',
            'status' => InvoiceStatus::Posted->value,
            'invoice_number' => $creditNumber,
            'fiscal_year' => $number['fiscal_year'] ?? null,
            'sequential_number' => $number['sequential_number'] ?? null,
            'related_invoice_id' => (string) $originalInvoice->id,
            'jurisdiction' => $originalInvoice->jurisdiction ?? 'IT',
            'split_payment' => (bool) $originalInvoice->split_payment,
            'sdi_excluded' => $sdiExcluded,
            'date' => $date,
            'due_date' => $today->toDateString(),
            'currency' => $originalInvoice->currency ?? 'EUR',
            'exchange_rate' => (string) ($originalInvoice->exchange_rate ?? '1'),
            'exchange_rate_source' => $originalInvoice->exchange_rate_source ?? InvoiceExchangeRateResolver::SOURCE_DOCUMENT,
            'exchange_rate_date' => $originalInvoice->exchange_rate_date,
            'subtotal' => $computed['subtotal'],
            'discount_amount' => $computed['discount_total'],
            'total_net' => $computed['total_net'],
            'total_tax' => $computed['total_tax'],
            'total_gross' => $computed['total_gross'],
            'receivable_amount' => '0',
            'total_paid' => $computed['total_gross'],
            'payment_status' => 'paid',
            'posted_at' => now(),
            'posted_by' => $actorId,
            'notes' => $data['notes'] ?? 'Credit note for invoice '.$originalInvoice->invoice_number,
            'created_by' => $actorId,
        ]);

        $actualCreditNoteId = (string) $creditNote->id;

        foreach ($computed['items'] as $index => $line) {
            $item = $items[$index];

            SalesInvoiceItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'invoice_id' => $actualCreditNoteId,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Credit item',
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_rate' => $line['tax_rate'],
                'discount_percentage' => $line['discount_percentage'],
                'line_total' => $line['line_total'],
                'revenue_account_id' => $item['revenue_account_id'] ?? null,
                'cost_center_id' => $item['cost_center_id'] ?? null,
            ]);
        }

        $this->fiscal->applyToCreditNote($creditNote, $originalInvoice);
        $this->fiscal->assertIssuable($creditNote->load('items'));
        $this->assertCreditWithinOriginal($originalInvoice, $creditNote);
        $creditNote->total_paid = $creditNote->total_gross;
        $creditNote->save();

        $this->completeCreditNote($creditNote, $originalInvoice, $items, (string) ($data['created_by'] ?? 'system'));

        return $creditNote->load('items');
    }

    /**
     * Settles an issued invoice with an issued credit note of the same customer. Both documents are locked, so two
     * concurrent applications cannot together credit more than the note holds or the invoice still owes.
     *
     * @param  string|float  $amount  decimal amount; a float is accepted for internal callers and rounded to the cent
     *
     * @throws ValidationException when the documents do not match or the amount exceeds what is available
     */
    public function applyCreditNote(SalesInvoice $creditNote, SalesInvoice $targetInvoice, string|float $amount, string $userId): InvoiceCreditApplication
    {
        $amount = Decimal::round2(is_float($amount) ? number_format($amount, 6, '.', '') : $amount);

        return DB::transaction(function () use ($creditNote, $targetInvoice, $amount, $userId): InvoiceCreditApplication {
            $ids = [(string) $creditNote->id, (string) $targetInvoice->id];
            sort($ids);

            $locked = SalesInvoice::withoutGlobalScope(TenantScope::class)->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $note = $locked->get((string) $creditNote->id);
            $target = $locked->get((string) $targetInvoice->id);

            if ($note === null || $target === null) {
                throw ValidationException::withMessages(['credit_note' => __('sales.credit_note_not_applicable')]);
            }

            $this->assertCreditApplicable($note, $target, $amount);

            $application = InvoiceCreditApplication::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => (string) $note->tenant_id,
                'credit_note_id' => (string) $note->id,
                'original_invoice_id' => (string) $target->id,
                'applied_amount' => $amount,
                'application_date' => $this->today((string) $note->tenant_id)->toDateString(),
                'notes' => 'Credit application from '.$note->invoice_number.' to '.$target->invoice_number,
                'applied_by' => Str::isUuid($userId) ? $userId : null,
            ]);

            $newPaid = Decimal::round2(Decimal::add((string) ($target->total_paid ?? '0'), $amount));

            $target->total_paid = $newPaid;
            $target->payment_status = Decimal::compare($newPaid, $target->amountDue()) >= 0 ? 'paid' : 'partially_paid';
            $target->save();

            $targetInvoice->setRawAttributes($target->getAttributes(), true);

            return $application;
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertCreditApplicable(SalesInvoice $note, SalesInvoice $target, string $amount): void
    {
        if (! $note->isCreditNote() || $note->status !== InvoiceStatus::Posted->value) {
            throw ValidationException::withMessages(['credit_note' => __('sales.credit_note_not_applicable')]);
        }

        if ($target->isCreditNote() || $target->invoice_type === 'proforma' || $target->status !== InvoiceStatus::Posted->value) {
            throw ValidationException::withMessages(['target_invoice_id' => __('sales.credit_note_target_not_issued')]);
        }

        if ((string) $note->tenant_id !== (string) $target->tenant_id || (string) $note->contact_id !== (string) $target->contact_id) {
            throw ValidationException::withMessages(['target_invoice_id' => __('sales.credit_note_other_customer')]);
        }

        if (! Decimal::isPositive($amount)) {
            throw ValidationException::withMessages(['amount' => __('sales.credit_note_amount_positive')]);
        }

        $applied = InvoiceCreditApplication::withoutGlobalScope(TenantScope::class)->where('credit_note_id', $note->id)
            ->pluck('applied_amount')
            ->reduce(static fn (string $sum, mixed $value): string => Decimal::add($sum, DocumentTotalsCalculator::decimal($value)), '0');
        $available = Decimal::sub($note->amountDue(), $applied);

        if (Decimal::compare($amount, $available) > 0) {
            throw ValidationException::withMessages(['amount' => __('sales.credit_note_exceeds_available')]);
        }

        if (Decimal::compare($amount, $target->outstandingAmount()) > 0) {
            throw ValidationException::withMessages(['amount' => __('sales.payment_exceeds_balance')]);
        }
    }

    /**
     * Stores the event in the outbox and dispatches it to the listeners of the request, in one call.
     */
    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publishAndDispatch($event);
    }
}
