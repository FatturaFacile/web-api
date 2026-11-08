<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\SalesOrderStatus;
use App\Models\Tenant;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Contracts\SalesOrderServiceInterface;
use Modules\Invoicing\Events\SalesOrderApprovedEvent;
use Modules\Invoicing\Events\SalesOrderCreatedEvent;
use Modules\Invoicing\Events\SalesOrderStockReservedEvent;
use Modules\Invoicing\Models\CustomerAgreement;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

/**
 * Life cycle of the sales orders of the invoicing module: creation with price determination and decimal totals,
 * confirmation behind the credit check, approval, stock reservation through the inventory module and cancellation.
 * Every status change goes through the SalesOrderStatus state machine, on a locked row.
 */
final class SalesOrderService implements SalesOrderServiceInterface
{
    public function __construct(
        private readonly PricingAndDiscountServiceInterface $pricingService,
        private readonly CustomerCreditServiceInterface $creditService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DocumentNumberingService $numbering,
        private readonly ?TenantClock $clock = null,
        private readonly ?SalesOrderStockReservations $reservations = null,
    ) {}

    /**
     * Creates a draft order. A product line without a unit price is priced by the price determination of the sales
     * cycle (price list, customer list, promotions); a line with a price keeps it. Every line states its VAT rate.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createOrder(array $data, array $items): SalesOrder
    {
        $tenantId = (string) $data['tenant_id'];
        $orderId = (string) Str::uuid7();
        $date = CarbonImmutable::parse($data['date'] ?? ($this->clock ?? app(TenantClock::class))->today(Tenant::query()->find($tenantId)));
        $calculatedItems = $this->priceLines($data, $items, $date);
        $totals = (new DocumentTotalsCalculator)->calculateDecimal($calculatedItems);

        foreach ($totals['items'] as $index => $line) {
            $calculatedItems[$index] = array_merge($calculatedItems[$index], $line);
        }

        return DB::transaction(fn (): SalesOrder => $this->insertOrder($orderId, $tenantId, $data, $calculatedItems, $totals, $date));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function priceLines(array $data, array $items, CarbonImmutable $date): array
    {
        $contactId = isset($data['contact_id']) ? (string) $data['contact_id'] : null;
        $priceListId = isset($data['price_list_id']) ? (string) $data['price_list_id'] : null;
        $policyId = isset($data['discount_policy_id']) ? (string) $data['discount_policy_id'] : null;

        // The order amount at list prices, for the promotions with a minimum order amount.
        $orderAmount = '0';
        foreach ($items as $item) {
            $quantity = DocumentTotalsCalculator::decimal($item['quantity'] ?? '1');
            $price = isset($item['unit_price']) ? DocumentTotalsCalculator::decimal($item['unit_price'])
                : (isset($item['product_id']) ? $this->pricingService->determineLinePrice((string) $item['product_id'], $quantity, $contactId, $priceListId, null, $date->toDateString())['list_price'] : '0');
            $orderAmount = Decimal::add($orderAmount, Decimal::mul($quantity, $price));
        }

        $calculated = [];
        foreach ($items as $item) {
            if (! isset($item['tax_rate'])) {
                throw new InvalidArgumentException('Every order line must state its VAT rate.');
            }

            $quantity = DocumentTotalsCalculator::decimal($item['quantity'] ?? '1');
            $pricing = isset($item['product_id']) && (! isset($item['unit_price']) || ! isset($item['discount_percentage']))
                ? $this->pricingService->determineLinePrice((string) $item['product_id'], $quantity, $contactId, $priceListId, $policyId, $date->toDateString(), Decimal::round2($orderAmount))
                : null;

            $calculated[] = array_merge($item, [
                'quantity' => $quantity,
                'unit_price' => isset($item['unit_price']) ? DocumentTotalsCalculator::decimal($item['unit_price']) : ($pricing['unit_price'] ?? '0'),
                'discount_percentage' => isset($item['discount_percentage']) ? DocumentTotalsCalculator::decimal($item['discount_percentage']) : ($pricing['discount_percentage'] ?? '0'),
                'tax_rate' => DocumentTotalsCalculator::decimal($item['tax_rate']),
            ]);
        }

        return $calculated;
    }

    /**
     * Inserts the order numbered on the `sales_order` series of the tenant, the same gapless series the sales API of the
     * app uses, inside the transaction of the caller so that a failed insert does not burn the number.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $calculatedItems
     * @param  array{subtotal: string, discount_total: string, total_net: string, total_tax: string, total_gross: string}  $totals
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
            'total_net' => $totals['total_net'],
            'total_tax' => $totals['total_tax'],
            'total_gross' => $totals['total_gross'],
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
                'quantity' => $item['quantity'],
                'quantity_reserved' => '0',
                'quantity_delivered' => '0',
                'quantity_invoiced' => '0',
                'unit_price' => $item['unit_price'],
                'tax_rate' => $item['tax_rate'],
                'discount_percentage' => $item['discount_percentage'],
                'line_total' => $item['line_total'],
                'cost_price' => DocumentTotalsCalculator::decimal($item['cost_price'] ?? '0'),
                'uom' => $item['uom'] ?? 'EA',
                'notes' => $item['notes'] ?? null,
            ]);
        }

        if (! empty($data['customer_agreement_id'])) {
            $agreement = CustomerAgreement::query()->whereKey($data['customer_agreement_id'])->lockForUpdate()->first();

            if ($agreement !== null) {
                $agreement->update(['consumed_amount' => Decimal::round2(Decimal::add(DocumentTotalsCalculator::decimal($agreement->consumed_amount), $totals['total_gross']))]);
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

    /**
     * Confirms the order behind the credit check of the company (see CustomerCreditService): an order the check does
     * not pass is put on credit hold, one it passes (or passes with a warning) is confirmed.
     *
     * @throws ValidationException when the order cannot be confirmed from its status
     */
    public function submitOrder(SalesOrder $order, string $userId): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === SalesOrderStatus::OnHold->value) {
                throw ValidationException::withMessages(['status' => __('sales.order_on_hold_release_required')]);
            }

            SalesOrderStatus::assertTransition($locked->status, SalesOrderStatus::Confirmed);

            $creditResult = $this->creditService->checkOrderCredit($locked);

            if (! $creditResult['approved']) {
                $this->creditService->placeHold(
                    order: $locked,
                    holdType: 'credit_limit',
                    reason: $creditResult['reason'] ?? __('sales.credit_customer_on_hold'),
                    userId: $userId
                );
            } else {
                $locked->update([
                    'status' => SalesOrderStatus::Confirmed->value,
                    'confirmed_at' => $locked->confirmed_at ?? now(),
                ]);
            }

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order->load(['items', 'holds']);
        });
    }

    public function approveOrder(SalesOrder $order, string $userId): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            SalesOrderStatus::assertTransition($locked->status, SalesOrderStatus::Approved);
            $approverId = Str::isUuid($userId) ? $userId : null;

            $locked->update([
                'status' => SalesOrderStatus::Approved->value,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);

            $this->dispatchDomainEvent(new SalesOrderApprovedEvent(
                salesOrderId: (string) $locked->id,
                approvedBy: $userId,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) $locked->tenant_id
            ));

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    /**
     * Reserves the open quantities of the stock-relevant lines in the primary warehouse of the company.
     */
    public function reserveInventory(SalesOrder $order): SalesOrder
    {
        return $this->reserveInventoryIn($order, null, null);
    }

    /**
     * Reserves the open quantities of the stock-relevant lines in a warehouse (the primary one when none is given),
     * through the reservation service of the inventory module. A line gets what the warehouse can give; the inventory
     * status of the order says whether everything was reserved.
     *
     * @throws ValidationException when the order is closed or no warehouse exists
     */
    public function reserveInventoryIn(SalesOrder $order, ?string $warehouseId, ?string $userId): SalesOrder
    {
        return DB::transaction(function () use ($order, $warehouseId, $userId): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (SalesOrderStatus::tryFrom((string) $locked->status)?->isFinal() ?? true) {
                throw ValidationException::withMessages(['status' => __('sales.order_closed_for_reservation')]);
            }

            $reservations = $this->reservations();
            $warehouse = $reservations->warehouseFor($warehouseId) ?? throw ValidationException::withMessages(['warehouse_id' => __('sales.no_warehouse')]);
            $locked->load('items');
            $dueDate = $locked->getRawOriginal('due_date');
            $expiresAt = $dueDate !== null ? CarbonImmutable::parse((string) $dueDate)->endOfDay()->toDateTimeString() : null;
            $status = $reservations->reserve($locked, $warehouse, $userId !== null && Str::isUuid($userId) ? $userId : null, $expiresAt);

            $locked->update(['inventory_status' => $status]);
            $locked->load('items');

            $this->dispatchDomainEvent(new SalesOrderStockReservedEvent(
                salesOrderId: (string) $locked->id,
                reservedItems: $locked->items
                    ->filter(static fn (SalesOrderItem $item): bool => Decimal::isPositive(DocumentTotalsCalculator::decimal($item->quantity_reserved)))
                    ->map(static fn (SalesOrderItem $item): array => [
                        'product_id' => (string) $item->product_id,
                        'quantity' => (float) $item->quantity_reserved,
                        'sales_order_item_id' => (string) $item->id,
                    ])->values()->all(),
                tenantId: (string) $locked->tenant_id,
                companyId: (string) $locked->tenant_id
            ));

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order->setRelation('items', $locked->items);
        });
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publishAndDispatch($event);
    }

    /**
     * Cancels the order and gives its reserved stock back.
     */
    public function cancelOrder(SalesOrder $order, string $reason, string $userId): SalesOrder
    {
        return DB::transaction(function () use ($order, $reason): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            SalesOrderStatus::assertTransition($locked->status, SalesOrderStatus::Cancelled);
            $locked->load('items');

            $this->reservations()->release($locked);

            $locked->update([
                'status' => SalesOrderStatus::Cancelled->value,
                'inventory_status' => 'released',
                'notes' => trim(($locked->notes ?? '')."\n".__('sales.order_cancellation_note', ['reason' => $reason])),
            ]);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    private function reservations(): SalesOrderStockReservations
    {
        return $this->reservations ?? app(SalesOrderStockReservations::class);
    }
}
