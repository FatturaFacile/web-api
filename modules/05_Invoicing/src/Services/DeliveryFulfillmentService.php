<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Events\DeliveryNoteIssuedEvent;
use Modules\Invoicing\Events\GoodsDispatchedEvent;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

final class DeliveryFulfillmentService implements DeliveryFulfillmentServiceInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{sales_order_item_id: string, quantity: float, batch_number?: ?string, serial_number?: ?string}>  $items
     */
    public function createDeliveryNote(SalesOrder $order, array $deliveryData, array $items): SalesDeliveryNote
    {
        $tenantId = (string) $order->tenant_id;
        $deliveryId = (string) Str::uuid7();
        $ddtNumber = $deliveryData['ddt_number'] ?? 'DDT-'.date('Y').'-'.strtoupper(Str::random(6));

        $deliveryNote = SalesDeliveryNote::create([
            'id' => $deliveryId,
            'tenant_id' => $tenantId,
            'contact_id' => (string) $order->contact_id,
            'sales_order_id' => (string) $order->id,
            'warehouse_id' => $deliveryData['warehouse_id'] ?? null,
            'ddt_number' => $ddtNumber,
            'date' => $deliveryData['date'] ?? now()->toDateString(),
            'transport_reason' => $deliveryData['transport_reason'] ?? 'Vendita',
            'goods_appearance' => $deliveryData['goods_appearance'] ?? 'A vista',
            'packages_count' => (int) ($deliveryData['packages_count'] ?? 1),
            'gross_weight_kg' => (float) ($deliveryData['gross_weight_kg'] ?? 0.0),
            'carrier_name' => $deliveryData['carrier_name'] ?? null,
            'carrier_license_plate' => $deliveryData['carrier_license_plate'] ?? null,
            'departure_time' => $deliveryData['departure_time'] ?? now(),
            'shipping_address' => $deliveryData['shipping_address'] ?? $order->shipping_address,
            'courier' => $deliveryData['courier'] ?? null,
            'tracking_number' => $deliveryData['tracking_number'] ?? null,
            'status' => 'draft',
            'shipping_status' => 'draft',
            'notes' => $deliveryData['notes'] ?? null,
            'created_by' => ! empty($deliveryData['created_by']) && Str::isUuid((string) $deliveryData['created_by']) ? (string) $deliveryData['created_by'] : null,
        ]);

        $actualDeliveryId = (string) $deliveryNote->id;

        foreach ($items as $item) {
            $orderItem = SalesOrderItem::find($item['sales_order_item_id']);
            $deliveredQty = (float) $item['quantity'];

            SalesDeliveryNoteItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'delivery_note_id' => $actualDeliveryId,
                'sales_order_item_id' => $orderItem !== null ? (string) $orderItem->id : null,
                'product_id' => $orderItem !== null ? (string) $orderItem->product_id : null,
                'description' => $orderItem !== null ? (string) $orderItem->description : 'Delivered item',
                'quantity' => $deliveredQty,
                'unit_price' => $orderItem !== null ? (float) $orderItem->unit_price : 0.0,
                'tax_rate' => $orderItem !== null ? (float) $orderItem->tax_rate : 0.0,
                'batch_number' => $item['batch_number'] ?? null,
                'serial_number' => $item['serial_number'] ?? null,
            ]);

            if ($orderItem !== null) {
                $newDelivered = (float) $orderItem->quantity_delivered + $deliveredQty;
                $orderItem->update([
                    'quantity_delivered' => $newDelivered,
                    'quantity_reserved' => max(0.0, (float) $orderItem->quantity_reserved - $deliveredQty),
                ]);
            }
        }

        // Re-evaluate order fulfillment status
        $order->load('items');
        $allFulfilled = true;
        $anyDelivered = false;

        foreach ($order->items as $oi) {
            if ((float) $oi->quantity_delivered < (float) $oi->quantity) {
                $allFulfilled = false;
            }
            if ((float) $oi->quantity_delivered > 0) {
                $anyDelivered = true;
            }
        }

        $order->update([
            'fulfillment_status' => $allFulfilled ? 'fulfilled' : ($anyDelivered ? 'partial' : 'unfulfilled'),
            'status' => $allFulfilled ? 'shipped' : 'in_fulfillment',
        ]);

        return $deliveryNote->load('items');
    }

    public function dispatchDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote
    {
        $deliveryNote->load('items');
        $tenantId = (string) $deliveryNote->tenant_id;

        $dispatchedItems = [];
        foreach ($deliveryNote->items as $item) {
            $dispatchedItems[] = [
                'product_id' => (string) $item->product_id,
                'quantity' => (float) $item->quantity,
                'batch_number' => $item->batch_number,
                'serial_number' => $item->serial_number,
                'delivery_note_item_id' => (string) $item->id,
            ];
        }

        $deliveryNote->update([
            'status' => 'issued',
            'shipping_status' => 'dispatched',
            'shipped_at' => now(),
        ]);

        $this->dispatchDomainEvent(new GoodsDispatchedEvent(
            deliveryNoteId: (string) $deliveryNote->id,
            salesOrderId: $deliveryNote->sales_order_id,
            warehouseId: (string) ($deliveryNote->warehouse_id ?? $tenantId),
            dispatchedItems: $dispatchedItems,
            dispatchedAt: now()->toISOString(),
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        $this->dispatchDomainEvent(new DeliveryNoteIssuedEvent(
            deliveryNoteId: (string) $deliveryNote->id,
            ddtNumber: (string) $deliveryNote->ddt_number,
            customerId: (string) $deliveryNote->contact_id,
            salesOrderId: $deliveryNote->sales_order_id,
            tenantId: $tenantId,
            companyId: $tenantId
        ));

        return $deliveryNote;
    }

    public function confirmDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote
    {
        $deliveryNote->update([
            'shipping_status' => 'delivered',
            'delivered_at' => now(),
        ]);

        return $deliveryNote;
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publish($event);
        $this->eventDispatcher->dispatch($event);
    }
}
