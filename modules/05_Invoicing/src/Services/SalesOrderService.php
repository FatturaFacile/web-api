<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\SalesOrderStatus;
use App\Models\Tenant;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DocumentNumberingService $numbering,
        private readonly ?TenantClock $clock = null
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createOrder(array $data, array $items): SalesOrder
    {
        $tenantId = (string) $data['tenant_id'];
        $orderId = (string) Str::uuid7();

        $calculatedItems = [];
        foreach ($items as $item) {
            $productId = isset($item['product_id']) ? (string) $item['product_id'] : null;
            $pricing = $productId !== null ? $this->pricingService->resolvePrice(
                productId: $productId,
                quantity: (float) ($item['quantity'] ?? 1.0),
                priceListId: $data['price_list_id'] ?? null,
                discountPolicyId: $data['discount_policy_id'] ?? null
            ) : ['unit_price' => (float) ($item['unit_price'] ?? 0.0), 'discount_percentage' => (float) ($item['discount_percentage'] ?? 0.0)];

            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : $pricing['unit_price'];
            $discountPct = isset($item['discount_percentage']) ? (float) $item['discount_percentage'] : $pricing['discount_percentage'];
            $taxRate = (float) ($item['tax_rate'] ?? throw new InvalidArgumentException('Every order line must state its VAT rate.'));
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
        $date = CarbonImmutable::parse($data['date'] ?? ($this->clock ?? app(TenantClock::class))->today(Tenant::query()->find($tenantId)));

        return DB::transaction(fn (): SalesOrder => $this->insertOrder($orderId, $tenantId, $data, $calculatedItems, $totals, $date));
    }

    /**
     * Inserts the order numbered on the `sales_order` series of the tenant, the same gapless series the sales API of the
     * app uses, inside the transaction of the caller so that a failed insert does not burn the number.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $calculatedItems
     * @param  array<string, float>  $totals
     */
    private function insertOrder(string $orderId, string $tenantId, array $data, array $calculatedItems, array $totals, CarbonImmutable $date): SalesOrder
    {
        $orderNumber = $this->numbering->next('sales_order', $date->year, $tenantId)['number'];

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
            'date' => $date->toDateString(),
            'due_date' => $data['due_date'] ?? $date->addDays(30)->toDateString(),
            'currency' => $data['currency'] ?? 'EUR',
            'subtotal' => $totals['subtotal'],
            'discount_amount' => $totals['discount_total'],
            'total_net' => $totals['net_total'],
            'total_tax' => $totals['tax_total'],
            'total_gross' => $totals['gross_total'],
            'status' => SalesOrderStatus::Draft->value,
            'credit_status' => 'pending_check',
            'inventory_status' => 'pending',
            'fulfillment_status' => 'unfulfilled',
            'billing_status' => 'unbilled',
            'payment_terms' => $data['payment_terms'] ?? null,
            'shipping_method' => $data['shipping_method'] ?? null,
            'shipping_address' => $data['shipping_address'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : null,
        ]);

        $actualOrderId = (string) $order->id;

        foreach ($calculatedItems as $item) {
            SalesOrderItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'sales_order_id' => $actualOrderId,
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

        $this->dispatchDomainEvent(new SalesOrderCreatedEvent(
            salesOrderId: $actualOrderId,
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

        SalesOrderStatus::assertTransition($order->status, SalesOrderStatus::Confirmed);

        $order->update([
            'status' => SalesOrderStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);

        return $order;
    }

    public function approveOrder(SalesOrder $order, string $userId): SalesOrder
    {
        SalesOrderStatus::assertTransition($order->status, SalesOrderStatus::Approved);
        $quoteUserId = Str::isUuid($userId) ? $userId : (string) Str::uuid7();

        $order->update([
            'status' => SalesOrderStatus::Approved->value,
            'approved_by' => $quoteUserId,
            'approved_at' => now(),
        ]);

        $this->dispatchDomainEvent(new SalesOrderApprovedEvent(
            salesOrderId: (string) $order->id,
            approvedBy: $quoteUserId,
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

        $this->dispatchDomainEvent(new SalesOrderStockReservedEvent(
            salesOrderId: (string) $order->id,
            reservedItems: $reservedItems,
            tenantId: (string) $order->tenant_id,
            companyId: (string) $order->tenant_id
        ));

        return $order;
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publish($event);
        $this->eventDispatcher->dispatch($event);
    }

    public function cancelOrder(SalesOrder $order, string $reason, string $userId): SalesOrder
    {
        SalesOrderStatus::assertTransition($order->status, SalesOrderStatus::Cancelled);
        $order->load('items');

        // Release any reservations
        foreach ($order->items as $item) {
            $item->update(['quantity_reserved' => 0.0]);
        }

        $order->update([
            'status' => SalesOrderStatus::Cancelled->value,
            'inventory_status' => 'pending',
            'notes' => trim(($order->notes ?? '')."\nCancellation reason: ".$reason),
        ]);

        return $order;
    }
}
