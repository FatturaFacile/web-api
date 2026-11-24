<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Enums\QuoteStatus;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\WorkOrder;
use App\Services\Invoicing\InvoiceService;
use App\Services\ItalianSdi\InvoiceFiscalService;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder as ModuleSalesOrder;
use Modules\Invoicing\Services\OrderBillingService;

/**
 * Turns the documents of the sales API of the app (orders, delivery notes, quotes, proformas, work reports) into issued
 * invoices. It decides which source can be invoiced and records the conversion on the source; the invoice itself is
 * always created and issued by the invoicing service (orders and delivery notes through its billing of sources, so
 * partial billing, down-payment deduction and order billing status are the same as in the invoicing module).
 */
class ConvertToInvoice
{
    public function __construct(
        private readonly DocumentEventRecorder $events,
        private readonly InvoiceService $invoices,
        private readonly InvoicingServiceInterface $invoicing,
        private readonly OrderBillingService $orderBilling,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Invoices what is left to invoice on a sales order. The invoice that closes the order deducts its down payments
     * line by line, at their VAT rate.
     */
    public function fromOrder(SalesOrder $order): Invoice
    {
        return DB::transaction(function () use ($order): Invoice {
            $issued = $this->invoicing->issueInvoiceFromOrder(ModuleSalesOrder::query()->findOrFail($order->id), ['notes' => $order->notes], $this->actor());
            $invoice = Invoice::query()->with('items')->findOrFail($issued->id);
            $this->events->record($invoice, 'invoiced_from_order', ['source_id' => $order->getKey()]);

            return $invoice;
        });
    }

    /**
     * Issues a down-payment invoice for a percentage of the order net total, split per VAT rate. The down payments of an
     * order can never exceed its net total.
     */
    public function depositFromOrder(SalesOrder $order, string $percentage): Invoice
    {
        return DB::transaction(function () use ($order, $percentage): Invoice {
            $issued = $this->orderBilling->issueDownPayment(ModuleSalesOrder::query()->findOrFail($order->id), DocumentTotalsCalculator::decimal($percentage), null, [], $this->actor());
            $invoice = Invoice::query()->with('items')->findOrFail($issued->id);
            $this->events->record($invoice, 'deposit_invoiced_from_order', ['source_id' => $order->getKey()]);

            return $invoice;
        });
    }

    /**
     * Invoice a quote directly (skipping the order step), once.
     */
    public function fromQuote(Quote $quote): Invoice
    {
        return DB::transaction(function () use ($quote): Invoice {
            $lockedQuote = Quote::where('id', $quote->id)->lockForUpdate()->firstOrFail();

            if (! QuoteStatus::allows($lockedQuote->status, QuoteStatus::Accepted)) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_not_convertible')]);
            }

            if (Invoice::where('quote_id', $lockedQuote->id)->exists()) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_already_invoiced')]);
            }

            $invoice = $this->build($lockedQuote, $lockedQuote->items->all(), ['quote_id' => $lockedQuote->id], 'invoiced_from_quote');
            $lockedQuote->update(['status' => QuoteStatus::Accepted->value]);

            return $invoice;
        });
    }

    /**
     * Invoices a delivery note, once. Lines without a price take it from their order line, and the order lines are
     * billed by the delivered quantities.
     */
    public function fromDeliveryNote(DeliveryNote $note): Invoice
    {
        return DB::transaction(function () use ($note): Invoice {
            $issued = $this->invoicing->issueInvoiceFromDeliveryNote(SalesDeliveryNote::query()->findOrFail($note->id), ['notes' => $note->notes], $this->actor());
            $invoice = Invoice::query()->with('items')->findOrFail($issued->id);
            $this->events->record($invoice, 'invoiced_from_delivery_note', ['source_id' => $note->getKey()]);

            return $invoice;
        });
    }

    /**
     * Turns a proforma into an invoice with its own number, linked to the proforma: the customer, currency, VAT regime,
     * payment details and Italian fiscal data (withholding, pension fund, stamp duty, declaration of intent) carry over.
     */
    public function fromProforma(Invoice $proforma): Invoice
    {
        return DB::transaction(function () use ($proforma): Invoice {
            $lockedProforma = Invoice::where('id', $proforma->id)->lockForUpdate()->firstOrFail();

            if ($lockedProforma->invoice_type !== 'proforma') {
                throw ValidationException::withMessages(['invoice' => __('sales.proforma_only')]);
            }

            if (Invoice::where('proforma_id', $lockedProforma->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => __('sales.proforma_already_converted')]);
            }

            $fiscal = array_filter(Arr::only($lockedProforma->getAttributes(), InvoiceFiscalService::INPUT_FIELDS), static fn (mixed $value): bool => $value !== null);

            return $this->build($lockedProforma, $lockedProforma->items->all(), ['proforma_id' => $lockedProforma->id], 'converted_from_proforma', [
                ...$fiscal,
                'jurisdiction' => $lockedProforma->jurisdiction,
                'split_payment' => (bool) $lockedProforma->split_payment,
                'reverse_charge' => (bool) $lockedProforma->reverse_charge,
                'sdi_excluded' => (bool) $lockedProforma->sdi_excluded,
                'payment_method' => $lockedProforma->payment_method,
                'payment_terms' => $lockedProforma->payment_terms,
                'bank_name' => $lockedProforma->bank_name,
                'bank_iban' => $lockedProforma->bank_iban,
            ]);
        });
    }

    /**
     * Invoice a completed or signed work report from its lines, once.
     */
    public function fromWorkOrder(WorkOrder $workOrder): Invoice
    {
        return DB::transaction(function () use ($workOrder): Invoice {
            $lockedWorkOrder = WorkOrder::where('id', $workOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedWorkOrder->status, ['completed', 'signed'], true)) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.not_invoiceable')]);
            }

            if ($lockedWorkOrder->lines->isEmpty()) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.no_lines')]);
            }

            $invoice = $this->build($lockedWorkOrder, $lockedWorkOrder->lines->all(), [], 'invoiced_from_work_order', ['notes' => $lockedWorkOrder->work_order_number]);
            $lockedWorkOrder->update(['status' => 'invoiced', 'invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * Issues an invoice from the lines of a source document through the invoicing service and records the conversion.
     *
     * @param  array<int, Model>  $lines
     * @param  array<string, mixed>  $links
     * @param  array<string, mixed>  $extra
     */
    private function build(Model $source, array $lines, array $links, string $event, array $extra = []): Invoice
    {
        $date = (string) ($extra['date'] ?? $this->clock->today(Tenant::query()->find($source->tenant_id))->toDateString());
        $payload = array_map(
            static fn (Model $line): array => $line->only(['product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'discount_percentage']),
            $lines
        );

        $invoice = $this->invoices->issue(array_merge([
            'tenant_id' => (string) $source->tenant_id,
            'contact_id' => (string) $source->contact_id,
            'invoice_type' => 'standard',
            'date' => $date,
            'currency' => $source->currency ?? 'EUR',
            'notes' => $source->notes,
        ], $links, $extra), $payload);

        $this->events->record($invoice, $event, ['source_id' => $source->getKey()]);

        return $invoice;
    }

    private function actor(): string
    {
        return (string) (Auth::id() ?? 'system');
    }
}
