<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Support\Decimal;

/**
 * Finds the purchase order of a supplier invoice. In order: the one the caller names, the one the invoice is already
 * linked to, the reference carried by the XML / eBill / OCR payload, the number of an open order of the supplier found
 * in the payload, and finally a deterministic fallback on the lines (supplier, description, quantity and unit price).
 */
final class PurchaseOrderResolver
{
    /** Share of the invoice lines the fallback has to recognise on an order. */
    private const float FallbackThreshold = 0.75;

    public function __construct(private readonly PurchaseOrderReferenceExtractor $references) {}

    /**
     * @return array{order: PurchaseOrder|null, source: string, value: string|null}
     */
    public function resolve(PassiveInvoice $invoice, ?string $purchaseOrderId = null): array
    {
        $supplierId = (string) $invoice->supplier_id;

        if ($purchaseOrderId !== null) {
            $order = $this->supplierOrders($supplierId)->whereKey($purchaseOrderId)->first();

            return ['order' => $order, 'source' => $order !== null ? 'explicit' : 'none', 'value' => $order?->po_number];
        }

        if ($invoice->purchase_order_id !== null) {
            $order = $this->supplierOrders($supplierId)->whereKey($invoice->purchase_order_id)->first();

            if ($order !== null) {
                return ['order' => $order, 'source' => 'linked', 'value' => $order->po_number];
            }
        }

        foreach ($this->references->extract($invoice) as $reference) {
            $order = $this->supplierOrders($supplierId)
                ->where(static fn ($query) => $query->where('po_number', $reference['value'])->orWhere('order_number', $reference['value']))
                ->first();

            if ($order !== null) {
                return ['order' => $order, 'source' => $reference['source'], 'value' => $reference['value']];
            }
        }

        $open = $this->supplierOrders($supplierId)->whereIn('status', $this->openStatuses())->get();

        foreach ($this->references->haystacks($invoice) as $haystack) {
            foreach ($open as $order) {
                $number = PurchaseOrderReferenceExtractor::normalize((string) ($order->po_number ?? $order->order_number));

                if ($number !== '' && str_contains($haystack['text'], $number)) {
                    return ['order' => $order, 'source' => $haystack['source'], 'value' => $order->po_number ?? $order->order_number];
                }
            }
        }

        $fallback = $this->byLines($invoice, $open);

        return ['order' => $fallback, 'source' => $fallback !== null ? 'fallback' : 'none', 'value' => $fallback?->po_number];
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    private function byLines(PassiveInvoice $invoice, Collection $orders): ?PurchaseOrder
    {
        $invoiceLines = $invoice->lines;

        if ($invoiceLines->isEmpty()) {
            return $this->byHeaderAmount($invoice, $orders);
        }

        $scores = [];

        foreach ($orders as $order) {
            $orderLines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->get();
            $recognised = $invoiceLines->filter(fn (PassiveInvoiceLine $line): bool => $this->recognises($line, $orderLines))->count();
            $scores[(string) $order->id] = $recognised / $invoiceLines->count();
        }

        arsort($scores);
        $ids = array_keys($scores);
        $best = $scores[$ids[0] ?? ''] ?? 0.0;

        if ($best < self::FallbackThreshold || (isset($ids[1]) && $scores[$ids[1]] >= $best)) {
            return null;
        }

        return $orders->firstWhere('id', $ids[0]);
    }

    /**
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    private function byHeaderAmount(PassiveInvoice $invoice, Collection $orders): ?PurchaseOrder
    {
        $net = Decimal::of((string) ($invoice->total_net ?? '0'), 2);

        if (! Decimal::isPositive($net)) {
            return null;
        }

        $candidates = $orders->filter(static fn (PurchaseOrder $order): bool => Decimal::cmp(Decimal::of((string) ($order->net_amount ?? $order->total_net), 2), $net, 2) === 0);

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $orderLines
     */
    private function recognises(PassiveInvoiceLine $line, Collection $orderLines): bool
    {
        $text = mb_strtolower(trim((string) $line->description));
        $price = Decimal::of((string) $line->unit_price, 6);

        foreach ($orderLines as $orderLine) {
            similar_text($text, mb_strtolower(trim((string) $orderLine->description)), $score);
            $samePrice = Decimal::cmp(Decimal::of((string) $orderLine->unit_price, 6), $price, 6) === 0;

            if ($score >= 80.0 && $samePrice) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Builder<PurchaseOrder>
     */
    private function supplierOrders(string $supplierId): Builder
    {
        return PurchaseOrder::query()->where('supplier_id', $supplierId);
    }

    /**
     * @return list<string>
     */
    private function openStatuses(): array
    {
        return [
            PurchaseOrderStatus::Approved->value,
            PurchaseOrderStatus::Sent->value,
            PurchaseOrderStatus::Confirmed->value,
            PurchaseOrderStatus::PartiallyReceived->value,
            PurchaseOrderStatus::Received->value,
            PurchaseOrderStatus::Closed->value,
        ];
    }
}
