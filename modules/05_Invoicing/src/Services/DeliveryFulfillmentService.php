<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Enums\DeliveryNoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\DeliveryNote;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Events\DeliveryNoteIssuedEvent;
use Modules\Invoicing\Events\GoodsDispatchedEvent;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesDeliveryNoteItem;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

/**
 * The one writer of the sales delivery notes (DDT): the sales API of the app (order and quote conversions) and the
 * invoicing module both create and dispatch notes here.
 *
 * A note of an order ships part or all of the open quantity of its lines (ordered less delivered); what stays open is
 * the backorder of the order, shipped by later notes. Quantities are checked on locked order lines, in decimal
 * arithmetic, so two notes can never ship more than was ordered. The note carries the transport data of the DDT
 * (reason, goods appearance, packages, weights, carrier, shipping terms, who carries the goods), stored as given.
 *
 * A note is created as a draft and issued when the goods leave (dispatch): the stock of the inventory-tracked products
 * leaves the warehouse then, through the stock service of the inventory module, after the reservations of the order
 * lines are released so the issue finds them available; the open rest of the order is reserved again.
 */
final class DeliveryFulfillmentService implements DeliveryFulfillmentServiceInterface
{
    /**
     * Who carries the goods, as printed on the DDT ("trasporto a cura del").
     *
     * @var list<string>
     */
    public const array TransportBy = ['sender', 'recipient', 'carrier'];

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly DocumentNumberingService $numbering,
        private readonly ?TenantClock $clock = null,
        private readonly ?StockService $stock = null,
        private readonly ?SalesOrderStockReservations $reservations = null,
    ) {}

    /**
     * Creates a draft note for an order. Without items every open quantity is shipped.
     *
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{sales_order_item_id: string, quantity: float|int|string, batch_number?: ?string, serial_number?: ?string}>  $items
     *
     * @throws ValidationException when the order cannot be delivered or a quantity exceeds what is open
     */
    public function createDeliveryNote(SalesOrder $order, array $deliveryData, array $items): SalesDeliveryNote
    {
        $tenantId = (string) $order->tenant_id;
        $date = CarbonImmutable::parse($deliveryData['date'] ?? $this->today($tenantId));

        return DB::transaction(fn (): SalesDeliveryNote => $this->insertOrderNote($order, $deliveryData, $items, $tenantId, $date));
    }

    /**
     * Creates a draft note without an order (goods shipped straight from an accepted quote): the lines are given in full.
     *
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{product_id?: ?string, description: string, quantity: float|int|string, unit_price?: float|int|string|null, tax_rate?: float|int|string|null}>  $lines
     */
    public function createStandaloneDeliveryNote(string $tenantId, string $contactId, array $deliveryData, array $lines): SalesDeliveryNote
    {
        $date = CarbonImmutable::parse($deliveryData['date'] ?? $this->today($tenantId));

        return DB::transaction(function () use ($tenantId, $contactId, $deliveryData, $lines, $date): SalesDeliveryNote {
            $note = $this->insertHeader($tenantId, $contactId, null, $deliveryData, $date);

            foreach ($lines as $line) {
                SalesDeliveryNoteItem::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'delivery_note_id' => (string) $note->id,
                    'product_id' => $line['product_id'] ?? null,
                    'description' => (string) $line['description'],
                    'quantity' => bcadd(DocumentTotalsCalculator::decimal($line['quantity']), '0', 4),
                    'unit_price' => bcadd(DocumentTotalsCalculator::decimal($line['unit_price'] ?? '0'), '0', 4),
                    'tax_rate' => DocumentTotalsCalculator::decimal($line['tax_rate'] ?? '0'),
                ]);
            }

            return $note->load('items');
        });
    }

    /**
     * @param  array<string, mixed>  $deliveryData
     * @param  array<int, array{sales_order_item_id: string, quantity: float|int|string, batch_number?: ?string, serial_number?: ?string}>  $items
     */
    private function insertOrderNote(SalesOrder $callerOrder, array $deliveryData, array $items, string $tenantId, CarbonImmutable $date): SalesDeliveryNote
    {
        $order = SalesOrder::query()->whereKey($callerOrder->id)->lockForUpdate()->firstOrFail();

        if ($order->status === SalesOrderStatus::Draft->value || (! SalesOrderStatus::allows($order->status, SalesOrderStatus::InFulfillment) && ! SalesOrderStatus::allows($order->status, SalesOrderStatus::Shipped))) {
            throw ValidationException::withMessages(['sales_order_id' => __('sales.order_not_deliverable')]);
        }

        $orderItems = SalesOrderItem::query()->where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->get()->keyBy(static fn (SalesOrderItem $item): string => (string) $item->id);
        $toShip = $this->quantitiesToShip($orderItems, $items);

        $deliveryNote = $this->insertHeader($tenantId, (string) $order->contact_id, $order, $deliveryData, $date);

        foreach ($toShip as ['item' => $orderItem, 'quantity' => $quantity, 'batch_number' => $batch, 'serial_number' => $serial]) {
            SalesDeliveryNoteItem::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'delivery_note_id' => (string) $deliveryNote->id,
                'sales_order_item_id' => (string) $orderItem->id,
                'product_id' => $orderItem->product_id,
                'description' => (string) $orderItem->description,
                'quantity' => $quantity,
                'unit_price' => bcadd(DocumentTotalsCalculator::decimal($orderItem->unit_price), '0', 4),
                'tax_rate' => DocumentTotalsCalculator::decimal($orderItem->tax_rate),
                'uom' => $orderItem->uom,
                'batch_number' => $batch,
                'serial_number' => $serial,
            ]);

            $orderItem->update(['quantity_delivered' => bcadd(Decimal::add(DocumentTotalsCalculator::decimal($orderItem->quantity_delivered), $quantity), '0', 4)]);
        }

        $this->refreshFulfillment($order);
        $callerOrder->setRawAttributes($order->getAttributes(), true);

        return $deliveryNote->load('items');
    }

    /**
     * The lines and quantities a note ships: the requested ones, each within its open quantity, or every open quantity.
     *
     * @param  Collection<string, SalesOrderItem>  $orderItems
     * @param  array<int, array{sales_order_item_id: string, quantity: float|int|string, batch_number?: ?string, serial_number?: ?string}>  $items
     * @return list<array{item: SalesOrderItem, quantity: string, batch_number: ?string, serial_number: ?string}>
     */
    private function quantitiesToShip($orderItems, array $items): array
    {
        $requested = [];

        if ($items === []) {
            foreach ($orderItems as $id => $orderItem) {
                $requested[$id] = ['quantity' => $this->openQuantity($orderItem), 'batch_number' => null, 'serial_number' => null];
            }
        } else {
            foreach ($items as $index => $item) {
                $id = (string) $item['sales_order_item_id'];

                if (! $orderItems->has($id)) {
                    throw ValidationException::withMessages(["items.{$index}.sales_order_item_id" => __('sales.billing_line_not_of_order')]);
                }

                $previous = $requested[$id]['quantity'] ?? '0';
                $requested[$id] = [
                    'quantity' => Decimal::add($previous, DocumentTotalsCalculator::decimal($item['quantity'])),
                    'batch_number' => $item['batch_number'] ?? null,
                    'serial_number' => $item['serial_number'] ?? null,
                ];
            }
        }

        $toShip = [];

        foreach ($requested as $id => $request) {
            $orderItem = $orderItems->get($id);
            $quantity = bcadd($request['quantity'], '0', 4);

            if (! Decimal::isPositive($quantity)) {
                continue;
            }

            if (Decimal::compare($quantity, $this->openQuantity($orderItem)) > 0) {
                throw ValidationException::withMessages(['lines' => __('sales.delivery_quantity_exceeds_open', ['line' => (string) $orderItem->description])]);
            }

            $toShip[] = ['item' => $orderItem, 'quantity' => $quantity, 'batch_number' => $request['batch_number'], 'serial_number' => $request['serial_number']];
        }

        if ($toShip === []) {
            throw ValidationException::withMessages(['lines' => __('sales.nothing_to_deliver')]);
        }

        return $toShip;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertHeader(string $tenantId, string $contactId, ?SalesOrder $order, array $data, CarbonImmutable $date): SalesDeliveryNote
    {
        $ddtNumber = $this->numbering->next('delivery_note', $date->year, $tenantId)['number'];

        // Reason, goods appearance and packages have schema defaults: a value not given keeps the default.
        $defaulted = array_filter([
            'transport_reason' => $data['transport_reason'] ?? null,
            'goods_appearance' => $data['goods_appearance'] ?? null,
            'packages_count' => isset($data['packages_count']) ? (int) $data['packages_count'] : null,
        ], static fn (mixed $value): bool => $value !== null);

        return SalesDeliveryNote::create($defaulted + [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'contact_id' => $contactId,
            'sales_order_id' => $order?->id,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'ddt_number' => $ddtNumber,
            'date' => $date->toDateString(),
            'gross_weight_kg' => isset($data['gross_weight_kg']) ? DocumentTotalsCalculator::decimal($data['gross_weight_kg']) : null,
            'net_weight_kg' => isset($data['net_weight_kg']) ? DocumentTotalsCalculator::decimal($data['net_weight_kg']) : null,
            'carrier_name' => $data['carrier_name'] ?? null,
            'carrier_license_plate' => $data['carrier_license_plate'] ?? null,
            'departure_time' => $data['departure_time'] ?? null,
            'shipping_terms' => $data['shipping_terms'] ?? null,
            'transport_by' => $data['transport_by'] ?? null,
            'shipping_address' => $data['shipping_address'] ?? $order?->shipping_address,
            'courier' => $data['courier'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'show_prices' => (bool) ($data['show_prices'] ?? false),
            'status' => DeliveryNoteStatus::Draft->value,
            'shipping_status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => ! empty($data['created_by']) && Str::isUuid((string) $data['created_by']) ? (string) $data['created_by'] : null,
        ]);
    }

    /**
     * Fulfilment and status of the order from the delivered quantities of its lines: fulfilled lines ship the order, a
     * partial delivery puts it in fulfilment.
     */
    private function refreshFulfillment(SalesOrder $order): void
    {
        $order->load('items');
        $allFulfilled = true;
        $anyDelivered = false;

        foreach ($order->items as $item) {
            $delivered = DocumentTotalsCalculator::decimal($item->quantity_delivered);

            if (Decimal::compare($delivered, DocumentTotalsCalculator::decimal($item->quantity)) < 0) {
                $allFulfilled = false;
            }

            if (Decimal::isPositive($delivered)) {
                $anyDelivered = true;
            }
        }

        $orderStatus = $allFulfilled ? SalesOrderStatus::Shipped : SalesOrderStatus::InFulfillment;
        SalesOrderStatus::assertTransition($order->status, $orderStatus);

        $order->update([
            'fulfillment_status' => $allFulfilled ? 'fulfilled' : ($anyDelivered ? 'partial' : 'unfulfilled'),
            'status' => $orderStatus->value,
        ]);
    }

    /**
     * Issues the note when the goods leave: the stock of the tracked products leaves the warehouse of the note (the
     * primary warehouse when none is set). A note leaves once: dispatching it again would move the stock twice.
     *
     * @throws ValidationException when the note was already dispatched or invoiced
     */
    public function dispatchDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote
    {
        return DB::transaction(function () use ($deliveryNote, $userId): SalesDeliveryNote {
            $locked = SalesDeliveryNote::query()->whereKey($deliveryNote->id)->lockForUpdate()->firstOrFail();

            if ($locked->shipping_status === 'dispatched' || $locked->shipping_status === 'delivered' || $locked->status !== DeliveryNoteStatus::Draft->value) {
                throw ValidationException::withMessages(['status' => __('sales.delivery_note_already_dispatched')]);
            }

            DeliveryNoteStatus::assertTransition($locked->status, DeliveryNoteStatus::Issued);
            $locked->load('items');
            $tenantId = (string) $locked->tenant_id;
            $order = $locked->sales_order_id !== null ? SalesOrder::query()->with('items')->whereKey($locked->sales_order_id)->lockForUpdate()->first() : null;
            $reservations = $this->reservations ?? app(SalesOrderStockReservations::class);
            $hadReservations = $order !== null && $order->items->contains(static fn (SalesOrderItem $item): bool => Decimal::isPositive(DocumentTotalsCalculator::decimal($item->quantity_reserved)));

            if ($hadReservations) {
                $reservations->release($order);
            }

            $stock = $this->stock ?? app(StockService::class);
            $warehouse = $locked->warehouse_id !== null ? Warehouse::query()->find($locked->warehouse_id) : $stock->defaultWarehouse();
            $stockNote = DeliveryNote::query()->findOrFail($locked->id);
            $stock->recordForLines('out', $stockNote, $stockNote->items()->get(), $warehouse);

            if ($hadReservations) {
                $target = $reservations->warehouseFor($locked->warehouse_id);

                if ($target !== null) {
                    $order->load('items');
                    $order->update(['inventory_status' => $reservations->reserve($order, $target, Str::isUuid($userId) ? $userId : null)]);
                }
            }

            $now = ($this->clock ?? app(TenantClock::class))->now(Tenant::query()->find($tenantId));

            $locked->update([
                'status' => DeliveryNoteStatus::Issued->value,
                'shipping_status' => 'dispatched',
                'shipped_at' => now(),
                'departure_time' => $locked->departure_time ?? $now->toDateTimeString(),
            ]);

            $this->dispatchDomainEvent(new GoodsDispatchedEvent(
                deliveryNoteId: (string) $locked->id,
                salesOrderId: $locked->sales_order_id,
                warehouseId: $warehouse !== null ? (string) $warehouse->getKey() : '',
                dispatchedItems: $locked->items->map(static fn (SalesDeliveryNoteItem $item): array => [
                    'product_id' => (string) $item->product_id,
                    'quantity' => (float) $item->quantity,
                    'batch_number' => $item->batch_number,
                    'serial_number' => $item->serial_number,
                    'delivery_note_item_id' => (string) $item->id,
                ])->values()->all(),
                dispatchedAt: now()->toISOString(),
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            $this->dispatchDomainEvent(new DeliveryNoteIssuedEvent(
                deliveryNoteId: (string) $locked->id,
                ddtNumber: (string) $locked->ddt_number,
                customerId: (string) $locked->contact_id,
                salesOrderId: $locked->sales_order_id,
                tenantId: $tenantId,
                companyId: $tenantId
            ));

            $deliveryNote->setRawAttributes($locked->getAttributes(), true);

            return $deliveryNote->setRelation('items', $locked->items);
        });
    }

    /**
     * Records that the customer received the goods of a dispatched note.
     *
     * @throws ValidationException when the note has not been dispatched
     */
    public function confirmDelivery(SalesDeliveryNote $deliveryNote, string $userId): SalesDeliveryNote
    {
        return DB::transaction(function () use ($deliveryNote): SalesDeliveryNote {
            $locked = SalesDeliveryNote::query()->whereKey($deliveryNote->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->shipping_status, ['dispatched', 'delivered'], true)) {
                throw ValidationException::withMessages(['status' => __('sales.delivery_note_not_dispatched')]);
            }

            $locked->update(['shipping_status' => 'delivered', 'delivered_at' => $locked->delivered_at ?? now()]);
            $deliveryNote->setRawAttributes($locked->getAttributes(), true);

            return $deliveryNote;
        });
    }

    /**
     * Deletes a draft note and gives its quantities back to the order lines. An issued note is a transport document
     * that has left with the goods: it cannot be deleted (goods that come back are a sales return).
     *
     * @throws ValidationException when the note is issued, or its order was already shipped
     */
    public function deleteDraft(SalesDeliveryNote $deliveryNote): void
    {
        DB::transaction(function () use ($deliveryNote): void {
            $locked = SalesDeliveryNote::query()->with('items')->whereKey($deliveryNote->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== DeliveryNoteStatus::Draft->value) {
                throw ValidationException::withMessages(['status' => __('sales.delivery_note_issued_not_deletable')]);
            }

            if ($locked->sales_order_id !== null) {
                $order = SalesOrder::query()->whereKey($locked->sales_order_id)->lockForUpdate()->firstOrFail();

                if ($order->status === SalesOrderStatus::Shipped->value || SalesOrderStatus::tryFrom((string) $order->status)?->isFinal()) {
                    throw ValidationException::withMessages(['status' => __('sales.delivery_note_order_closed')]);
                }

                foreach ($locked->items as $item) {
                    if ($item->sales_order_item_id === null) {
                        continue;
                    }

                    $orderItem = SalesOrderItem::query()->whereKey($item->sales_order_item_id)->lockForUpdate()->first();
                    $delivered = Decimal::sub(DocumentTotalsCalculator::decimal($orderItem?->quantity_delivered), DocumentTotalsCalculator::decimal($item->quantity));
                    $orderItem?->update(['quantity_delivered' => bcadd(Decimal::isNegative($delivered) ? '0' : $delivered, '0', 4)]);
                }

                $order->load('items');
                $anyDelivered = $order->items->contains(static fn (SalesOrderItem $item): bool => Decimal::isPositive(DocumentTotalsCalculator::decimal($item->quantity_delivered)));
                $order->update(['fulfillment_status' => $anyDelivered ? 'partial' : 'unfulfilled']);
            }

            $locked->items->each(static fn (SalesDeliveryNoteItem $item): ?bool => $item->delete());
            $locked->delete();
        });
    }

    /**
     * Ordered less delivered, never negative.
     */
    private function openQuantity(SalesOrderItem $item): string
    {
        $open = Decimal::sub(DocumentTotalsCalculator::decimal($item->quantity), DocumentTotalsCalculator::decimal($item->quantity_delivered));

        return bcadd(Decimal::isPositive($open) ? $open : '0', '0', 4);
    }

    private function today(string $tenantId): string
    {
        return ($this->clock ?? app(TenantClock::class))->today(Tenant::query()->find($tenantId))->toDateString();
    }

    private function dispatchDomainEvent(DomainEventInterface $event): void
    {
        $this->eventDispatcher->publishAndDispatch($event);
    }
}
