<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Events\SalesOrderApprovedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesOrderStockReservedEvent;
use Modules\Invoicing\Models\CustomerAgreement;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

final class SalesOrderService implements SalesOrderServiceInterface
{
    public function __construct(
        private readonly PricingAndDiscountServiceInterface $pricingService,
        private readonly CustomerCreditServiceInterface $creditService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $items
     */
    public function createOrder(array $data, array $items): SalesOrder
    {
        $tenantId = (string) $data['tenant_id'];
        $orderId = (string) Str::uuid7();

        $calculatedItems = [];
        foreach ($items as $item) {
            $pricing = $this->pricingService->resolvePrice(
                productId: (string) $item['product_id'],
                quantity: (float) ($item['quantity'] ?? 1.0),
                priceListId: $data['price_list_id'] ?? null,
                discountPolicyId: $data['discount_policy_id'] ?? null
            );

            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : $pricing['unit_price'];
            $discountPct = isset($item['discount_percentage']) ? (float) $item['discount_percentage'] : $pricing['discount_percentage'];
            $taxRate = (float) ($item['tax_rate'] ?? 22.0);
            $qty = (float) ($item['quantity'] ?? 1.0);

            $lineNet = round(($qty * $unitPrice) * (1 - ($discountPct / 100.0)), 2);

            $calculatedItems[] = array_merge($item, [
                'unit_price' => $unitPrice,
                'discount_percentage' => $discountPct,
                'tax_rate' => $taxRate,
                'line_total' => $lineNet,
            ]);
        }

        $totals = $this->pricingService->calculateOrderTotals($calculatedItems, $data['discount_policy_id'] ?? null);

        $orderNumber = $data['order_number'] ?? 'SO-'.date('Y').'-'.strtoupper(Str::random(6));

        $order = SalesOrder::create([
            'id' => $orderId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $data['contact_id'],
            'quote_id' => $data['quote_id'] ?? null,
            'customer_agreement_id' => $data['customer_agreement_id'] ?? null,
            'price_list_id' => $data['price_list_id'] ?? null,
            'discount_policy_id' => $data['discount_policy_id'] ?? null,
            'sales_rep_id' => $data['sales_rep_id'] ?? null,
            'order_number' => $orderNumber,
            'date' => $data['date'] ?? now()->toDateString(),
            'due_date' => $data['due_date'] ?? now()->addDays(30)->toDateString(),
            'currency' => $data['currency'] ?? 'EUR',
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_total'],
            'total_net' => $totals['net_total'],
            'total_tax' => $totals['tax_total'],
            'total_gross' => $totals['gross_total'],
            'status' => 'draft',
            'credit_status' => 'pending_check',
            'inventory_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'billing_status' => 'unbilled',
            'payment_terms' => $data['payment_terms'] ?? null,
            'shipping_method' => $data['shipping_method'] ?? null,
            'shipping_address' => $data['shipping_address'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);

        foreach ($calculatedItems as $item) {
            SalesOrderItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'sales_order_id' => $orderId,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Product item',
                'quantity' => (float) ($item['quantity'] ?? 1.0),
                'quantity_reserved' => 0.0,
                'quantity_delivered' => 0.0,
                'quantity_invoiced' => 0.0,
                'unit_price' => (float) ($item['unit_price'] ?? 0.0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0.0),
                'discount_percentage' => (float) ($item['discount_percentage'] ?? 0.0),
                'line_total' => (float) ($item['line_total'] ?? 0.0),
                'cost_price' => (float) ($item['cost_price'] ?? 0.0),
                'uom' => $item['uom'] ?? 'EA',
                'notes' => $item['notes'] ?? null,
            ]);
        }

        // Update customer agreement consumed amount if applicable
        if (! empty($data['customer_agreement_id'])) {
            $agreement = CustomerAgreement::find($data['customer_agreement_id']);
            if ($agreement !== null) {
                $agreement->increment('consumed_amount', $totals['gross_total']);
            }
        }

        $this->eventDispatcher->dispatch(new SalesOrderCreatedEvent(
            salesOrderId: $orderId,
            orderNumber: $orderNumber,
            customerId: (string) $order->contact_id,
            totalGross: (float) $order->total_gross,
            currency: (string) $order->currency,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $order->load('items');
    }

    public function submitOrder(SalesOrder $order, string $userId): SalesOrder
    {
        // Perform mandatory credit check
        $creditResult = $this->creditService->checkOrderCredit($order);

        if (! $creditResult['approved']) {
            $this->creditService->placeHold(
                order: $order,
                holdType: 'credit_limit',
                reason: $creditResult['reason'] ?? 'Credit check failed.',
                userId: $userId
            );

            return $order->fresh(['items', 'holds']);
        }

        $order->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        return $order;
    }

    public function approveOrder(SalesOrder $order, string $userId): SalesOrder
    {
        $order->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        $this->eventDispatcher->dispatch(new SalesOrderApprovedEvent(
            salesOrderId: (string) $order->id,
            approvedBy: $userId,
            tenantId: (string) $order->tenant_id,
            companyId: (string) $order->tenant_id
        ));

        return $order;
    }

    public function reserveInventory(SalesOrder $order): SalesOrder
    {
        $order->load('items');
        $reservedItems = [];

        foreach ($order->items as $item) {
            $qtyToReserve = (float) $item->quantity;
            $item->update([
                'quantity_reserved' => $qtyToReserve,
            ]);

            $reservedItems[] = [
                'product_id' => (string) $item->product_id,
                'quantity' => $qtyToReserve,
                'sales_order_item_id' => (string) $item->id,
            ];
        }

        $order->update([
            'inventory_status' => 'reserved',
        ]);

        $this->eventDispatcher->dispatch(new SalesOrderStockReservedEvent(
            salesOrderId: (string) $order->id,
            reservedItems: $reservedItems,
            tenantId: (string) $order->tenant_id,
            companyId: (string) $order->tenant_id
        ));

        return $order;
    }

    public function cancelOrder(SalesOrder $order, string $reason, string $userId): SalesOrder
    {
        $order->load('items');

        // Release any reservations
        foreach ($order->items as $item) {
            $item->update(['quantity_reserved' => 0.0]);
        }

        $order->update([
            'status' => 'cancelled',
            'inventory_status' => 'pending',
            'notes' => trim(($order->notes ?? '')."\nCancellation reason: ".$reason),
        ]);

        return $order;
    }
}
