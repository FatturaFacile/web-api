<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Services\Sales\DocumentTotalsCalculator;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesInvoiceItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

/**
 * The lines a sales document can be billed with, and the billing progress they leave on the sales order. Every billing
 * flow (order, partial order, delivery note, deferred invoice of several delivery notes) builds its lines here and
 * records here what it billed, so the invoiced quantity of an order line and the billing status of the order are kept
 * by one piece of code whatever document billed them.
 *
 * The callers lock the source documents (order, delivery notes) before using this class: the quantities it reads and
 * writes are then stable for the whole transaction of the invoice.
 */
final class SalesBillingLines
{
    public const string STATUS_UNBILLED = 'unbilled';

    public const string STATUS_PARTIALLY_BILLED = 'partially_billed';

    public const string STATUS_BILLED = 'billed';

    /**
     * The lines of an order still to be invoiced. Without `$requested` every open quantity is billed; with it only the
     * requested quantities of the requested lines, each at most what is still open on its line.
     *
     * @param  list<array{sales_order_item_id: string, quantity: string|int|float}>|null  $requested
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException when a requested line is not of the order or exceeds the open quantity
     */
    public function openOrderLines(SalesOrder $order, ?array $requested = null): array
    {
        $order->loadMissing('items');
        $itemsById = $order->items->keyBy(static fn (SalesOrderItem $item): string => (string) $item->id);
        $wanted = null;

        if ($requested !== null) {
            $wanted = [];

            foreach ($requested as $index => $line) {
                $itemId = (string) $line['sales_order_item_id'];
                $item = $itemsById->get($itemId);

                if ($item === null) {
                    throw ValidationException::withMessages(["lines.{$index}.sales_order_item_id" => __('sales.billing_line_not_of_order')]);
                }

                $quantity = DocumentTotalsCalculator::decimal($line['quantity']);

                if (Decimal::compare($quantity, $this->openQuantity($item)) > 0) {
                    throw ValidationException::withMessages(["lines.{$index}.quantity" => __('sales.billing_quantity_exceeds_open')]);
                }

                $wanted[$itemId] = Decimal::add($wanted[$itemId] ?? '0', $quantity);
            }
        }

        $lines = [];

        foreach ($order->items as $item) {
            $open = $this->openQuantity($item);
            $quantity = $wanted === null ? $open : ($wanted[(string) $item->id] ?? '0');

            if (! Decimal::isPositive($quantity) || Decimal::compare($quantity, $open) > 0) {
                continue;
            }

            $lines[] = [
                'sales_order_item_id' => (string) $item->id,
                'product_id' => $item->product_id,
                'description' => (string) $item->description,
                'quantity' => $this->quantity($quantity),
                'unit_price' => DocumentTotalsCalculator::decimal($item->unit_price),
                'tax_rate' => DocumentTotalsCalculator::decimal($item->tax_rate ?? '0'),
                'discount_percentage' => DocumentTotalsCalculator::decimal($item->discount_percentage ?? '0'),
                'uom' => (string) ($item->uom ?? 'EA'),
            ];
        }

        return $lines;
    }

    /**
     * The lines of a delivery note. A note printed without prices takes price and discount from its order line.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveryNoteLines(SalesDeliveryNote $note): array
    {
        $note->loadMissing('items');
        $orderItemIds = $note->items->pluck('sales_order_item_id')->filter()->map(static fn (mixed $id): string => (string) $id)->all();
        $orderItems = $orderItemIds === []
            ? collect()
            : SalesOrderItem::withoutGlobalScope(TenantScope::class)->whereKey($orderItemIds)->get()->keyBy(static fn (SalesOrderItem $item): string => (string) $item->id);
        $lines = [];

        foreach ($note->items as $item) {
            $orderItem = $item->sales_order_item_id !== null ? $orderItems->get((string) $item->sales_order_item_id) : null;
            $price = $item->getAttribute('unit_price') ?? $orderItem?->getAttribute('unit_price') ?? '0';

            $lines[] = [
                'delivery_note_item_id' => (string) $item->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'product_id' => $item->product_id,
                'description' => (string) $item->description,
                'quantity' => $this->quantity(DocumentTotalsCalculator::decimal($item->quantity)),
                'unit_price' => DocumentTotalsCalculator::decimal($price),
                'tax_rate' => DocumentTotalsCalculator::decimal($item->getAttribute('tax_rate') ?? $orderItem?->getAttribute('tax_rate') ?? '0'),
                'discount_percentage' => $item->getAttribute('unit_price') === null ? DocumentTotalsCalculator::decimal($orderItem?->getAttribute('discount_percentage') ?? '0') : '0',
                'uom' => (string) ($item->uom ?? 'EA'),
            ];
        }

        return $lines;
    }

    /**
     * Adds the billed quantities to their order lines and updates the billing status of every order they belong to. A
     * line billed beyond its ordered quantity (a delivery note with more than ordered) caps the order line at billed.
     *
     * @param  iterable<array<string, mixed>|SalesInvoiceItem>  $billedLines
     */
    public function recordBilledQuantities(iterable $billedLines): void
    {
        $billed = [];

        foreach ($billedLines as $line) {
            $itemId = $line instanceof SalesInvoiceItem ? $line->sales_order_item_id : ($line['sales_order_item_id'] ?? null);
            $quantity = $line instanceof SalesInvoiceItem ? $line->quantity : ($line['quantity'] ?? '0');

            if ($itemId !== null && $itemId !== '') {
                $billed[(string) $itemId] = Decimal::add($billed[(string) $itemId] ?? '0', DocumentTotalsCalculator::decimal($quantity));
            }
        }

        if ($billed === []) {
            return;
        }

        $items = SalesOrderItem::withoutGlobalScope(TenantScope::class)->whereKey(array_keys($billed))->lockForUpdate()->get();

        foreach ($items as $item) {
            // A query update: lines written by the sales API of the app may carry no tenant (generic rows), which the
            // tenant guard of the model refuses to save; the order row is locked by the caller.
            SalesOrderItem::withoutGlobalScope(TenantScope::class)->whereKey($item->id)->update([
                'quantity_invoiced' => $this->quantity(Decimal::add(DocumentTotalsCalculator::decimal($item->quantity_invoiced ?? '0'), $billed[(string) $item->id])),
            ]);
        }

        foreach ($items->pluck('sales_order_id')->unique() as $orderId) {
            $this->refreshBillingStatus((string) $orderId);
        }
    }

    /**
     * Recomputes the billing status of an order from the invoiced quantities of its lines.
     */
    public function refreshBillingStatus(string $orderId): string
    {
        $order = SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($orderId)->firstOrFail();
        $items = SalesOrderItem::withoutGlobalScope(TenantScope::class)->where('sales_order_id', $orderId)->get();
        $status = $this->billingStatusOf($items);

        if ($order->billing_status !== $status) {
            SalesOrder::withoutGlobalScope(TenantScope::class)->whereKey($orderId)->update(['billing_status' => $status]);
        }

        return $status;
    }

    /**
     * Whether the lines about to be billed close every open quantity of the order: that invoice is the final one.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function closesOrder(SalesOrder $order, array $lines): bool
    {
        $order->loadMissing('items');
        $billing = [];

        foreach ($lines as $line) {
            if (! empty($line['sales_order_item_id'])) {
                $billing[(string) $line['sales_order_item_id']] = Decimal::add($billing[(string) $line['sales_order_item_id']] ?? '0', DocumentTotalsCalculator::decimal($line['quantity']));
            }
        }

        foreach ($order->items as $item) {
            if (Decimal::compare($billing[(string) $item->id] ?? '0', $this->openQuantity($item)) < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The deduction lines of the down payments of an order not yet deducted: one negative line per line of every
     * issued down-payment invoice, at its VAT rate, for what is left of it.
     *
     * @return list<array<string, mixed>>
     */
    public function downPaymentDeductions(SalesOrder $order): array
    {
        $deposits = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->with('items')
            ->where('tenant_id', $order->tenant_id)
            ->where('sales_order_id', $order->id)
            ->where('is_deposit', true)
            ->where('status', 'posted')
            ->orderBy('date')
            ->orderBy('sequential_number')
            ->get();
        $lines = [];

        foreach ($deposits as $deposit) {
            foreach ($deposit->items as $item) {
                $remaining = $this->undeductedAmount($item);

                if (! Decimal::isPositive($remaining)) {
                    continue;
                }

                $lines[] = [
                    'description' => __('sales.deposit_deduction', ['number' => $deposit->invoice_number]),
                    'quantity' => '1',
                    'unit_price' => Decimal::round2(Decimal::neg($remaining)),
                    'tax_rate' => DocumentTotalsCalculator::decimal($item->tax_rate),
                    'discount_percentage' => '0',
                    'deducted_invoice_item_id' => (string) $item->id,
                ];
            }
        }

        return $lines;
    }

    /**
     * What is left to deduct of a down-payment line: its amount less the deduction lines already issued against it.
     */
    public function undeductedAmount(SalesInvoiceItem $depositItem): string
    {
        $deducted = SalesInvoiceItem::withoutGlobalScope(TenantScope::class)
            ->where('deducted_invoice_item_id', $depositItem->id)
            ->pluck('line_total')
            ->reduce(static fn (string $sum, mixed $amount): string => Decimal::add($sum, DocumentTotalsCalculator::decimal($amount)), '0');

        return Decimal::round2(Decimal::add(DocumentTotalsCalculator::decimal($depositItem->line_total), $deducted));
    }

    /**
     * Whether a delivery note was already billed, by an invoice of its own or by a line of a deferred invoice.
     */
    public function deliveryNoteBilled(SalesDeliveryNote $note): bool
    {
        if (SalesInvoice::withoutGlobalScope(TenantScope::class)->where('delivery_note_id', $note->id)->exists()) {
            return true;
        }

        $itemIds = $note->items()->withoutGlobalScope(TenantScope::class)->pluck('id')->all();

        return $itemIds !== [] && SalesInvoiceItem::withoutGlobalScope(TenantScope::class)->whereIn('delivery_note_item_id', $itemIds)->exists();
    }

    /**
     * @param  Collection<int, SalesOrderItem>  $items
     */
    private function billingStatusOf(Collection $items): string
    {
        $anyBilled = false;
        $allBilled = $items->isNotEmpty();

        foreach ($items as $item) {
            $invoiced = DocumentTotalsCalculator::decimal($item->quantity_invoiced ?? '0');
            $anyBilled = $anyBilled || Decimal::isPositive($invoiced);
            $allBilled = $allBilled && Decimal::compare($invoiced, DocumentTotalsCalculator::decimal($item->quantity)) >= 0;
        }

        return $allBilled ? self::STATUS_BILLED : ($anyBilled ? self::STATUS_PARTIALLY_BILLED : self::STATUS_UNBILLED);
    }

    private function openQuantity(SalesOrderItem $item): string
    {
        $open = Decimal::sub(DocumentTotalsCalculator::decimal($item->quantity), DocumentTotalsCalculator::decimal($item->quantity_invoiced ?? '0'));

        return Decimal::isPositive($open) ? $open : '0';
    }

    /**
     * Quantities are stored with four decimals.
     */
    private function quantity(string $value): string
    {
        return bcadd(Decimal::normalize($value), '0', 4);
    }
}
