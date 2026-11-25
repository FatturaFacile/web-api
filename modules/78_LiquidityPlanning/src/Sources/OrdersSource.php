<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Sources;

use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\LiquidityPlanning\Support\Flow;
use Modules\Procurement\Models\PurchaseOrder;

/**
 * The highly probable orders: the confirmed sales orders and the approved purchase orders, for the part not yet invoiced,
 * on the delivery date plus the payment term.
 */
final class OrdersSource implements FlowSource
{
    /** @var list<string> */
    private const array SalesStatuses = ['confirmed', 'approved', 'in_production', 'in_fulfillment', 'partially_delivered', 'shipped'];

    /** @var list<string> */
    private const array PurchaseStatuses = ['approved', 'sent', 'confirmed', 'partially_received'];

    public function flows(SourceContext $context): array
    {
        return [...$this->salesOrders($context), ...$this->purchaseOrders($context)];
    }

    /**
     * @return list<Flow>
     */
    private function salesOrders(SourceContext $context): array
    {
        $flows = [];
        $orders = SalesOrder::query()
            ->where('tenant_id', $context->tenantId)
            ->where('currency', $context->currency)
            ->whereIn('status', self::SalesStatuses)
            ->get();

        foreach ($orders as $order) {
            $remaining = Decimal::round2(Decimal::mul((string) $order->total_gross, Decimal::sub('1', Decimal::div((string) min(100, max(0, (float) ($order->invoiced_percentage ?? 0))), '100'))));

            if (! Decimal::isPositive($remaining)) {
                continue;
            }

            $delivery = $order->confirmed_delivery_date ?? $order->requested_delivery_date ?? $order->date;
            $due = $context->onOrAfterStart($delivery)->addDays(SourceContext::termDays($order->payment_terms));

            if ($due->lessThanOrEqualTo($context->end)) {
                $flows[] = new Flow($due, Flow::Inflow, Flow::Probable, 'sales_order', (string) $order->order_number, $remaining, 'lpApi.category.sales_order');
            }
        }

        return $flows;
    }

    /**
     * @return list<Flow>
     */
    private function purchaseOrders(SourceContext $context): array
    {
        $flows = [];
        $orders = PurchaseOrder::query()
            ->where('tenant_id', $context->tenantId)
            ->where('currency', $context->currency)
            ->whereIn('status', self::PurchaseStatuses)
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $lines = DB::table('purchase_order_lines')
            ->whereIn('purchase_order_id', $orders->pluck('id')->all())
            ->whereNull('deleted_at')
            ->groupBy('purchase_order_id')
            ->selectRaw('purchase_order_id, SUM(quantity_ordered) as ordered, SUM(quantity_invoiced) as invoiced')
            ->get()
            ->keyBy('purchase_order_id');

        foreach ($orders as $order) {
            $line = $lines->get($order->id);
            $openShare = $line === null || (float) $line->ordered <= 0
                ? '1'
                : Decimal::sub('1', Decimal::div((string) min((float) $line->invoiced, (float) $line->ordered), (string) $line->ordered));
            $remaining = Decimal::round2(Decimal::mul((string) ($order->total_gross ?? $order->total_amount ?? '0'), $openShare));

            if (! Decimal::isPositive($remaining)) {
                continue;
            }

            $delivery = $order->promised_delivery_date ?? $order->expected_delivery_date ?? $order->delivery_due_date ?? $order->date;
            $due = $context->onOrAfterStart($delivery)->addDays(SourceContext::termDays($order->payment_terms_code));

            if ($due->lessThanOrEqualTo($context->end)) {
                $flows[] = new Flow($due, Flow::Outflow, Flow::Probable, 'purchase_order', (string) $order->order_number, $remaining, 'lpApi.category.purchase_order');
            }
        }

        return $flows;
    }
}
