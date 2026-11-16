<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Logistics\Enums\DeliveryNoteStatus;
use Modules\Logistics\Enums\DeliveryNoteType;
use Modules\Logistics\Enums\TransportReason;
use Modules\Logistics\Support\LogisticsMessage;

/**
 * DDT lifecycle: free creation, fulfilment from one or more sales orders, issue, delivery confirmation, cancel.
 */
final class DeliveryNoteService
{
    /** Header attributes accepted from API clients (everything else is ignored). */
    private const HEADER_FIELDS = [
        'contact_id', 'supplier_id', 'carrier_id', 'origin_warehouse_id', 'destination_warehouse_id', 'warehouse_id',
        'shipping_address_id', 'shipping_address', 'transport_reason_code', 'carriage_terms', 'transport_by',
        'carrier_name', 'carrier_vat_number', 'carrier_license_plate', 'departure_time', 'goods_appearance',
        'packages_count', 'gross_weight_kg', 'net_weight_kg', 'volume_m3', 'incoterms', 'tracking_number',
        'carrier_tracking_url', 'courier', 'currency', 'notes', 'show_prices', 'date', 'stock_transfer_order_id',
    ];

    public function __construct(
        private readonly DeliveryNoteNumberingService $numbering,
        private readonly WarehouseStockService $stock,
        private readonly TenantContext $tenantContext,
        private readonly ShippingCarrierManager $carriers,
        private readonly DeliveryFulfillmentServiceInterface $fulfillment,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $data, array $items): SalesDeliveryNote
    {
        $type = DeliveryNoteType::from((string) ($data['type'] ?? DeliveryNoteType::OutboundSale->value));

        if ($items === []) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.DDT_NEEDS_LINE'));
        }
        if ($type->requiresCustomer() && empty($data['contact_id'])) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.DDT_CUSTOMER_REQUIRED'));
        }
        if (! $type->requiresCustomer() && empty($data['contact_id']) && empty($data['supplier_id']) && empty($data['destination_warehouse_id'])) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.DDT_RECIPIENT_REQUIRED'));
        }

        return DB::transaction(function () use ($data, $items, $type): SalesDeliveryNote {
            $note = $this->persistHeader($type, $data);
            $this->persistLines($note, $items);

            return $note->load('items');
        });
    }

    /**
     * Fulfils one or more sales orders of the same customer with a single DDT.
     *
     * @param  list<array{sales_order_id: string, items?: list<array{sales_order_item_id: string, quantity: float}>}>  $selection  omit `items` to ship the remaining quantity of the order
     * @param  array<string, mixed>  $data
     */
    public function createFromOrders(array $selection, array $data = []): SalesDeliveryNote
    {
        if ($selection === []) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.SELECT_ORDER'));
        }

        return DB::transaction(function () use ($selection, $data): SalesDeliveryNote {
            $orders = [];
            $lines = [];

            foreach ($selection as $entry) {
                /** @var SalesOrder $order */
                $order = SalesOrder::query()->with('items')->lockForUpdate()->findOrFail($entry['sales_order_id']);
                $orders[$order->id] = $order;

                $requested = [];
                foreach ($entry['items'] ?? [] as $row) {
                    $requested[(string) $row['sales_order_item_id']] = (float) $row['quantity'];
                }

                foreach ($order->items as $orderItem) {
                    $remaining = round((float) $orderItem->quantity - (float) $orderItem->quantity_delivered, 4);
                    $qty = $requested === [] ? $remaining : ($requested[(string) $orderItem->id] ?? 0.0);
                    if ($qty <= 0.0) {
                        continue;
                    }
                    if ($qty > $remaining) {
                        throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.CANNOT_SHIP_QTY', ['qty' => $qty, 'line' => $orderItem->id, 'remaining' => $remaining]));
                    }
                    $lines[] = [$orderItem, $qty];
                }
                foreach (array_keys($requested) as $itemId) {
                    if (! $order->items->contains('id', $itemId)) {
                        throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.ORDER_LINE_MISMATCH', ['line' => $itemId, 'order' => $order->id]));
                    }
                }
            }

            if ($lines === []) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.NOTHING_TO_SHIP'));
            }

            $customers = collect($orders)->pluck('contact_id')->map(fn ($id): string => (string) $id)->unique();
            if ($customers->count() > 1) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.ORDERS_SAME_CUSTOMER'));
            }
            $currencies = collect($orders)->pluck('currency')->map(fn ($c): string => (string) ($c ?? 'EUR'))->unique();
            if ($currencies->count() > 1) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.ORDERS_SAME_CURRENCY'));
            }

            $first = reset($orders);
            $data = array_merge([
                'contact_id' => (string) $first->contact_id,
                'sales_order_id' => count($orders) === 1 ? (string) $first->id : null,
                'currency' => $currencies->first(),
                'shipping_address' => $first->shipping_address,
            ], $data, ['type' => DeliveryNoteType::OutboundSale->value]);

            $note = $this->persistHeader(DeliveryNoteType::OutboundSale, $data);

            $itemsPayload = [];
            foreach ($lines as [$orderItem, $qty]) {
                $itemsPayload[] = [
                    'sales_order_item_id' => (string) $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'description' => (string) $orderItem->description,
                    'quantity' => $qty,
                    'unit_price' => (float) $orderItem->unit_price,
                    'tax_rate' => (float) $orderItem->tax_rate,
                    'uom' => $orderItem->uom ?? null,
                ];
                $orderItem->update([
                    'quantity_delivered' => round((float) $orderItem->quantity_delivered + $qty, 4),
                    'quantity_reserved' => max(0.0, (float) $orderItem->quantity_reserved - $qty),
                ]);
            }
            $this->persistLines($note, $itemsPayload);

            foreach ($orders as $order) {
                $this->refreshOrderFulfillment($order);
            }

            return $note->load('items');
        });
    }

    public function issue(SalesDeliveryNote $note): SalesDeliveryNote
    {
        return DB::transaction(function () use ($note): SalesDeliveryNote {
            $note = SalesDeliveryNote::query()->with('items')->lockForUpdate()->findOrFail($note->id);
            if ($note->status !== DeliveryNoteStatus::Draft->value) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.ISSUE_DRAFT_ONLY'));
            }

            $type = DeliveryNoteType::from((string) $note->type);
            $this->moveStockForIssue($note, $type);
            if ($type === DeliveryNoteType::OutboundSale) {
                // Publishes the GoodsDispatched / DeliveryNoteIssued domain events (outbox consumers).
                $this->fulfillment->dispatchDelivery($note, (string) (auth()->id() ?? 'system'));
            }

            $note->update([
                'status' => DeliveryNoteStatus::Issued->value,
                'shipping_status' => DeliveryNoteStatus::Issued->shippingStatus(),
                'issued_at' => now(),
                'departure_time' => $note->departure_time ?? now(),
            ]);

            return $note->load('items');
        });
    }

    /**
     * Goods physically leave: books the shipment through the carrier driver and moves issued -> in_transit.
     */
    public function startTransit(SalesDeliveryNote $note): SalesDeliveryNote
    {
        if ($note->status !== DeliveryNoteStatus::Issued->value) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DISPATCH_ISSUED_ONLY'));
        }

        $shipment = $this->carriers->driverFor($note)->createShipment($note);

        $note->update([
            'status' => DeliveryNoteStatus::InTransit->value,
            'shipping_status' => DeliveryNoteStatus::InTransit->shippingStatus(),
            'shipped_at' => now(),
            'tracking_number' => $note->tracking_number ?? $shipment->trackingNumber,
            'carrier_tracking_url' => $note->carrier_tracking_url ?? $shipment->trackingUrl,
        ]);

        return $note->load('items');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>|null  $items  replaces all lines when given
     */
    public function update(SalesDeliveryNote $note, array $data, ?array $items = null): SalesDeliveryNote
    {
        if ($note->status !== DeliveryNoteStatus::Draft->value) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.EDIT_DRAFT_ONLY'));
        }
        if ($note->sales_order_id !== null || $note->items()->whereNotNull('sales_order_item_id')->exists()) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.EDIT_FROM_ORDER'));
        }

        return DB::transaction(function () use ($note, $data, $items): SalesDeliveryNote {
            $this->assertWarehousesBelongToTenant($data);
            $note->update(array_intersect_key($data, array_flip(array_diff(self::HEADER_FIELDS, ['warehouse_id', 'stock_transfer_order_id']))));

            if ($items !== null) {
                if ($items === []) {
                    throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.DDT_NEEDS_LINE'));
                }
                $note->items()->delete();
                $this->persistLines($note, $items);
            }

            return $note->refresh()->load('items');
        });
    }

    public function delete(SalesDeliveryNote $note): void
    {
        if ($note->status !== DeliveryNoteStatus::Draft->value || $note->sales_order_id !== null) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DELETE_STANDALONE_DRAFT_ONLY'));
        }

        DB::transaction(function () use ($note): void {
            $note->items()->delete();
            $note->delete();
        });
    }

    public function markDelivered(SalesDeliveryNote $note, ?string $signatureName = null, ?Carbon $deliveredAt = null): SalesDeliveryNote
    {
        if (! in_array($note->status, [DeliveryNoteStatus::Issued->value, DeliveryNoteStatus::InTransit->value], true)) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DELIVER_STATUS'));
        }

        $note->update([
            'status' => DeliveryNoteStatus::Delivered->value,
            'shipping_status' => DeliveryNoteStatus::Delivered->shippingStatus(),
            'shipped_at' => $note->shipped_at ?? now(),
            'delivered_at' => $deliveredAt ?? now(),
            'recipient_signature_name' => $signatureName,
        ]);

        return $note->load('items');
    }

    public function cancel(SalesDeliveryNote $note): SalesDeliveryNote
    {
        return DB::transaction(function () use ($note): SalesDeliveryNote {
            $note = SalesDeliveryNote::query()->with('items')->lockForUpdate()->findOrFail($note->id);
            $status = $note->status;
            if (! in_array($status, [DeliveryNoteStatus::Draft->value, DeliveryNoteStatus::Issued->value, DeliveryNoteStatus::InTransit->value], true)) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.CANCEL_STATUS'));
            }
            if ($note->invoiceLinks()->exists()) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.CANCEL_INVOICED'));
            }
            if ($note->stock_transfer_order_id !== null) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.CANCEL_VIA_TRANSFER'));
            }

            $type = DeliveryNoteType::from((string) $note->type);
            if ($status !== DeliveryNoteStatus::Draft->value) {
                $this->reverseStockForIssue($note, $type);
                if ($status === DeliveryNoteStatus::InTransit->value) {
                    $this->carriers->driverFor($note)->cancelShipment($note);
                }
            }

            if ($type === DeliveryNoteType::OutboundSale) {
                $this->releaseOrderQuantities($note);
            }

            $note->update([
                'status' => DeliveryNoteStatus::Cancelled->value,
                'shipping_status' => DeliveryNoteStatus::Cancelled->shippingStatus(),
                'cancelled_at' => now(),
            ]);

            return $note->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistHeader(DeliveryNoteType $type, array $data): SalesDeliveryNote
    {
        $number = $this->numbering->next($type);
        $reason = isset($data['transport_reason_code'])
            ? TransportReason::from((string) $data['transport_reason_code'])
            : $type->defaultTransportReason();

        $attributes = array_intersect_key($data, array_flip(self::HEADER_FIELDS));
        unset($attributes['warehouse_id']);

        $this->assertWarehousesBelongToTenant($data);

        return SalesDeliveryNote::create(array_merge([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenantContext->getTenantId(),
            'sales_order_id' => $data['sales_order_id'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? $data['origin_warehouse_id'] ?? null,
            'ddt_number' => $number['number'],
            'sequential_number' => $number['sequential_number'],
            'fiscal_year' => $number['fiscal_year'],
            'date' => now()->toDateString(),
            'type' => $type->value,
            'transport_reason_code' => $reason->value,
            'transport_reason' => $reason->label(),
            'goods_appearance' => 'A vista',
            'packages_count' => 1,
            'currency' => 'EUR',
            'status' => DeliveryNoteStatus::Draft->value,
            'shipping_status' => 'draft',
        ], $attributes));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function persistLines(SalesDeliveryNote $note, array $items): void
    {
        foreach ($items as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            if ($quantity <= 0.0) {
                throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.LINE_QTY_POSITIVE'));
            }

            SalesDeliveryNoteItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $note->tenant_id,
                'delivery_note_id' => $note->id,
                'sales_order_item_id' => $item['sales_order_item_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? 'Item',
                'quantity' => $quantity,
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                'uom' => $item['uom'] ?? null,
                'batch_number' => $item['batch_number'] ?? null,
                'serial_number' => $item['serial_number'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertWarehousesBelongToTenant(array $data): void
    {
        foreach (['origin_warehouse_id', 'destination_warehouse_id', 'warehouse_id'] as $field) {
            if (! empty($data[$field]) && ! Warehouse::query()->whereKey($data[$field])->exists()) {
                throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.UNKNOWN_WAREHOUSE', ['field' => $field]));
            }
        }
    }

    private function moveStockForIssue(SalesDeliveryNote $note, DeliveryNoteType $type): void
    {
        // Documents generated by a transfer order already moved the stock at shipment time, and a sale DDT takes it out
        // through the delivery fulfillment (dispatchDelivery) of the invoicing module.
        if ($note->stock_transfer_order_id !== null || $type === DeliveryNoteType::OutboundSale) {
            return;
        }

        $from = $type === DeliveryNoteType::ReturnFromCustomer ? null : $note->origin_warehouse_id;
        $to = $note->destination_warehouse_id;
        if ($from === null && $to === null) {
            return;
        }

        foreach ($note->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $this->stock->move((string) $item->product_id, $from, $to, (float) $item->quantity, 'ddt_'.$type->value, SalesDeliveryNote::class, (string) $note->id);
        }
    }

    /**
     * Re-credits the origin with INBOUND_REVERSAL movements (and un-does a destination load, if any).
     */
    private function reverseStockForIssue(SalesDeliveryNote $note, DeliveryNoteType $type): void
    {
        $origin = $type === DeliveryNoteType::ReturnFromCustomer ? null : $note->origin_warehouse_id;
        $destination = $note->destination_warehouse_id;

        foreach ($note->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $productId = (string) $item->product_id;
            $qty = (float) $item->quantity;

            if ($destination !== null) {
                $this->stock->move($productId, $destination, null, $qty, 'ddt_cancel', SalesDeliveryNote::class, (string) $note->id);
            }
            if ($origin !== null) {
                $this->stock->move($productId, null, $origin, $qty, WarehouseStockService::INBOUND_REVERSAL, SalesDeliveryNote::class, (string) $note->id, 'DDT '.$note->ddt_number.' cancelled');
            }
        }
    }

    private function releaseOrderQuantities(SalesDeliveryNote $note): void
    {
        $orderIds = [];
        foreach ($note->items as $item) {
            if ($item->sales_order_item_id === null) {
                continue;
            }
            $orderItem = SalesOrderItem::query()->find($item->sales_order_item_id);
            if ($orderItem === null) {
                continue;
            }
            $orderItem->update(['quantity_delivered' => max(0.0, round((float) $orderItem->quantity_delivered - (float) $item->quantity, 4))]);
            $orderIds[(string) $orderItem->sales_order_id] = true;
        }

        foreach (array_keys($orderIds) as $orderId) {
            $order = SalesOrder::query()->find($orderId);
            if ($order !== null) {
                $this->refreshOrderFulfillment($order);
            }
        }
    }

    private function refreshOrderFulfillment(SalesOrder $order): void
    {
        $order->load('items');
        $all = true;
        $any = false;
        foreach ($order->items as $item) {
            if ((float) $item->quantity_delivered < (float) $item->quantity) {
                $all = false;
            }
            if ((float) $item->quantity_delivered > 0) {
                $any = true;
            }
        }

        $order->update([
            'fulfillment_status' => $all ? 'fulfilled' : ($any ? 'partial' : 'unfulfilled'),
            'status' => $all ? 'shipped' : ($any ? 'in_fulfillment' : $order->status),
        ]);
    }
}
