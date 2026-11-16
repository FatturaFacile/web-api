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
        private readonly DeliveryFulfillmentServiceInterface $fulfillment,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $data, array $items): SalesDeliveryNote
    {
        $type = DeliveryNoteType::from((string) ($data['type'] ?? DeliveryNoteType::OutboundSale->value));

        if ($items === []) {
            throw new InvalidArgumentException('A delivery note needs at least one line.');
        }
        if ($type->requiresCustomer() && empty($data['contact_id'])) {
            throw new InvalidArgumentException('A customer is required for this delivery note type.');
        }
        if (! $type->requiresCustomer() && empty($data['contact_id']) && empty($data['supplier_id']) && empty($data['destination_warehouse_id'])) {
            throw new InvalidArgumentException('A recipient (customer, supplier or destination warehouse) is required.');
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
            throw new InvalidArgumentException('Select at least one sales order.');
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
                        throw new DomainException("Cannot ship {$qty} of order line {$orderItem->id}: only {$remaining} remaining.");
                    }
                    $lines[] = [$orderItem, $qty];
                }
                foreach (array_keys($requested) as $itemId) {
                    if (! $order->items->contains('id', $itemId)) {
                        throw new InvalidArgumentException("Order line {$itemId} does not belong to order {$order->id}.");
                    }
                }
            }

            if ($lines === []) {
                throw new DomainException('Nothing left to ship on the selected orders.');
            }

            $customers = collect($orders)->pluck('contact_id')->map(fn ($id): string => (string) $id)->unique();
            if ($customers->count() > 1) {
                throw new DomainException('All selected orders must belong to the same customer.');
            }
            $currencies = collect($orders)->pluck('currency')->map(fn ($c): string => (string) ($c ?? 'EUR'))->unique();
            if ($currencies->count() > 1) {
                throw new DomainException('All selected orders must use the same currency.');
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
                throw new DomainException('Only draft delivery notes can be issued.');
            }

            $type = DeliveryNoteType::from((string) $note->type);

            if ($type === DeliveryNoteType::OutboundSale) {
                // Stock is released by the existing GoodsDispatched event consumers (Inventory module).
                $this->fulfillment->dispatchDelivery($note, (string) (auth()->id() ?? 'system'));
            } else {
                $this->moveStockForIssue($note, $type);
            }

            $note->refresh();
            $note->update([
                'status' => DeliveryNoteStatus::Issued->value,
                'shipping_status' => 'dispatched',
                'issued_at' => now(),
                'shipped_at' => $note->shipped_at ?? now(),
                'departure_time' => $note->departure_time ?? now(),
            ]);

            return $note->load('items');
        });
    }

    public function markDelivered(SalesDeliveryNote $note, ?string $signatureName = null, ?Carbon $deliveredAt = null): SalesDeliveryNote
    {
        if ($note->status !== DeliveryNoteStatus::Issued->value) {
            throw new DomainException('Only issued (in transit) delivery notes can be marked as delivered.');
        }

        $note->update([
            'status' => DeliveryNoteStatus::Delivered->value,
            'shipping_status' => 'delivered',
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
            if (! in_array($status, [DeliveryNoteStatus::Draft->value, DeliveryNoteStatus::Issued->value], true)) {
                throw new DomainException('Only draft or issued delivery notes can be cancelled.');
            }
            if ($note->invoiceLinks()->exists()) {
                throw new DomainException('A delivery note that has been invoiced cannot be cancelled.');
            }
            if ($note->stock_transfer_order_id !== null) {
                throw new DomainException('Delivery notes generated by a transfer order are cancelled through the transfer order.');
            }

            $type = DeliveryNoteType::from((string) $note->type);
            if ($status === DeliveryNoteStatus::Issued->value) {
                if ($type === DeliveryNoteType::OutboundSale) {
                    throw new DomainException('An issued sale DDT must be reversed with a return delivery note.');
                }
                $this->reverseStockForIssue($note, $type);
            }

            if ($type === DeliveryNoteType::OutboundSale) {
                $this->releaseOrderQuantities($note);
            }

            $note->update(['status' => DeliveryNoteStatus::Cancelled->value, 'shipping_status' => 'cancelled', 'cancelled_at' => now()]);

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
                throw new InvalidArgumentException('Line quantity must be greater than zero.');
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
                throw new InvalidArgumentException("Unknown warehouse for {$field}.");
            }
        }
    }

    private function moveStockForIssue(SalesDeliveryNote $note, DeliveryNoteType $type): void
    {
        // Documents generated by a transfer order already moved the stock at shipment time.
        if ($note->stock_transfer_order_id !== null) {
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

    private function reverseStockForIssue(SalesDeliveryNote $note, DeliveryNoteType $type): void
    {
        $from = $type === DeliveryNoteType::ReturnFromCustomer ? null : $note->origin_warehouse_id;
        $to = $note->destination_warehouse_id;
        if ($from === null && $to === null) {
            return;
        }

        foreach ($note->items as $item) {
            if ($item->product_id === null) {
                continue;
            }
            $this->stock->move((string) $item->product_id, $to, $from, (float) $item->quantity, 'ddt_cancel', SalesDeliveryNote::class, (string) $note->id);
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
