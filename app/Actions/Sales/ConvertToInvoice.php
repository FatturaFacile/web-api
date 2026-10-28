<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Subscription;
use App\Models\WorkOrder;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertToInvoice
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentTotalsCalculator $totals,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Invoice a sales order (all lines) or a delivery note (delivered lines).
     */
    public function fromOrder(SalesOrder $order): Invoice
    {
        return DB::transaction(function () use ($order): Invoice {
            $lockedOrder = SalesOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (Invoice::where('sales_order_id', $lockedOrder->id)->where('is_deposit', false)->exists()) {
                throw ValidationException::withMessages(['order' => __('sales.order_already_invoiced')]);
            }

            // Final invoice: previously issued deposit invoices are deducted line by line, keeping each tax rate.
            $deductions = Invoice::with('items')
                ->where('sales_order_id', $lockedOrder->id)
                ->where('is_deposit', true)
                ->orderBy('date')
                ->get()
                ->flatMap(fn (Invoice $deposit): array => $deposit->items->map(fn ($item): InvoiceItem => new InvoiceItem([
                    'description' => __('sales.deposit_deduction', ['number' => $deposit->invoice_number]),
                    'quantity' => 1,
                    'unit_price' => -1 * (float) $item->line_total,
                    'tax_rate' => $item->tax_rate,
                ]))->all())
                ->all();

            return $this->build($lockedOrder, array_merge($lockedOrder->items->all(), $deductions), ['sales_order_id' => $lockedOrder->id], 'invoiced_from_order');
        });
    }

    /**
     * Issue a deposit invoice for a percentage of the order, split per tax rate.
     * The sum of all deposits can never exceed the order net total.
     */
    public function depositFromOrder(SalesOrder $order, float $percentage): Invoice
    {
        return DB::transaction(function () use ($order, $percentage): Invoice {
            $lockedOrder = SalesOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status === 'cancelled') {
                throw ValidationException::withMessages(['order' => __('sales.order_cancelled')]);
            }

            if (Invoice::where('sales_order_id', $lockedOrder->id)->where('is_deposit', false)->exists()) {
                throw ValidationException::withMessages(['order' => __('sales.order_already_invoiced')]);
            }

            $alreadyRequested = (float) Invoice::where('sales_order_id', $lockedOrder->id)->where('is_deposit', true)->sum('total_net');
            $lines = $lockedOrder->items->groupBy(fn ($item): string => number_format((float) $item->tax_rate, 2, '.', ''))
                ->map(fn ($group, string $rate): InvoiceItem => new InvoiceItem([
                    'description' => __('sales.deposit_line', ['number' => $lockedOrder->order_number, 'percentage' => number_format($percentage, 2, '.', '')
                            |> (fn ($x) => rtrim($x, '0'))
                            |> (fn ($x) => rtrim($x, '.'))]),
                    'quantity' => 1,
                    'unit_price' => round((float) $group->sum('line_total') * $percentage / 100, 2),
                    'tax_rate' => (float) $rate,
                ]))
                ->values()
                ->all();

            $requested = array_sum(array_map(fn (InvoiceItem $line): float => (float) $line->unit_price, $lines));

            if ($alreadyRequested + $requested > (float) $lockedOrder->total_net + 0.01) {
                throw ValidationException::withMessages(['percentage' => __('sales.deposit_exceeds_order')]);
            }

            return $this->build($lockedOrder, $lines, ['sales_order_id' => $lockedOrder->id], 'deposit_invoiced_from_order', ['is_deposit' => true]);
        });
    }

    /**
     * Invoice a quote directly (skipping the order step), once.
     */
    public function fromQuote(Quote $quote): Invoice
    {
        return DB::transaction(function () use ($quote): Invoice {
            $lockedQuote = Quote::where('id', $quote->id)->lockForUpdate()->firstOrFail();

            if (in_array($lockedQuote->status, ['rejected', 'expired'], true)) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_not_convertible')]);
            }

            if (Invoice::where('quote_id', $lockedQuote->id)->exists()) {
                throw ValidationException::withMessages(['quote' => __('sales.quote_already_invoiced')]);
            }

            $invoice = $this->build($lockedQuote, $lockedQuote->items->all(), ['quote_id' => $lockedQuote->id], 'invoiced_from_quote');
            $lockedQuote->update(['status' => 'accepted']);

            return $invoice;
        });
    }

    public function fromDeliveryNote(DeliveryNote $note): Invoice
    {
        return DB::transaction(function () use ($note): Invoice {
            $lockedNote = DeliveryNote::where('id', $note->id)->lockForUpdate()->firstOrFail();

            if ($lockedNote->status === 'invoiced') {
                throw ValidationException::withMessages(['delivery_note' => __('sales.delivery_note_already_invoiced')]);
            }

            $invoice = $this->build($lockedNote, $lockedNote->items->all(), [
                'sales_order_id' => $lockedNote->sales_order_id,
                'delivery_note_id' => $lockedNote->id,
            ], 'invoiced_from_delivery_note');

            $lockedNote->update(['status' => 'invoiced']);

            return $invoice;
        });
    }

    /**
     * Invoice one billing period of a subscription, dated on the billing date.
     */
    public function fromSubscription(Subscription $subscription, CarbonInterface $billingDate): Invoice
    {
        $termDays = $subscription->contact?->payment_terms_days;

        return $this->build($subscription, $subscription->items->all(), ['subscription_id' => $subscription->id], 'invoiced_from_subscription', array_filter([
            'date' => $billingDate->toDateString(),
            'due_date' => $termDays !== null ? $billingDate->copy()->addDays((int) $termDays)->toDateString() : null,
            'notes' => $subscription->name,
        ]));
    }

    /**
     * Turn a proforma into a standard invoice with its own number, linking back to the proforma.
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

            return $this->build($lockedProforma, $lockedProforma->items->all(), ['proforma_id' => $lockedProforma->id], 'converted_from_proforma', [
                'jurisdiction' => $lockedProforma->jurisdiction,
                'split_payment' => $lockedProforma->split_payment,
                'reverse_charge' => $lockedProforma->reverse_charge,
            ]);
        });
    }

    /**
     * @param  array<int, Model>  $lines
     * @param  array<string, mixed>  $links
     * @param  array<string, mixed>  $extra
     */
    private function build(Model $source, array $lines, array $links, string $event, array $extra = []): Invoice
    {
        return DB::transaction(function () use ($source, $lines, $links, $event, $extra): Invoice {
            $computed = $this->totals->calculate(array_map(
                fn (Model $line): array => $line->only(['product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'discount_percentage']),
                $lines
            ));
            $date = (string) ($extra['date'] ?? now()->toDateString());
            $number = $this->numbering->next('invoice', (int) date('Y', strtotime($date)));

            $invoice = Invoice::create(array_merge([
                'tenant_id' => $source->tenant_id,
                'contact_id' => $source->contact_id,
                'invoice_type' => 'standard',
                'invoice_number' => $number['number'],
                'fiscal_year' => $number['fiscal_year'],
                'sequential_number' => $number['sequential_number'],
                'date' => $date,
                'currency' => $source->currency ?? 'EUR',
                'total_net' => $computed['total_net'],
                'total_tax' => $computed['total_tax'],
                'total_gross' => $computed['total_gross'],
                'payment_status' => 'unpaid',
                'notes' => $source->notes,
            ], $links, $extra));

            $invoice->items()->createMany($computed['items']);
            $this->events->record($invoice, $event, ['source_id' => $source->getKey()]);

            return $invoice->load('items');
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
}
