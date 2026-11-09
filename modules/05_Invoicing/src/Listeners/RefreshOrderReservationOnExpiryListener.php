<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Events\StockReservationExpiredEvent;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Invoicing\Services\SalesOrderStockReservations;

/**
 * When a reservation of a sales order line lapses in the inventory module, the order must stop promising that stock:
 * `quantity_reserved` of its lines and its `inventory_status` are recomputed from the reservations still active, so the
 * sales API, the availability check and the next delivery note see the real situation.
 */
final readonly class RefreshOrderReservationOnExpiryListener
{
    public function __construct(private SalesOrderStockReservations $reservations) {}

    public function handle(StockReservationExpiredEvent $event): void
    {
        if ($event->reservedForType !== SalesOrderStockReservations::ReservedForType || $event->tenantId === '') {
            return;
        }

        DB::transaction(function () use ($event): void {
            // The line is found by id and its order must belong to the tenant of the reservation: older lines may
            // carry no tenant of their own.
            $orderId = SalesOrderItem::withoutGlobalScopes()->whereKey($event->reservedForId)->value('sales_order_id');

            $order = $orderId !== null
                ? SalesOrder::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($orderId)->lockForUpdate()->first()
                : null;

            if ($order === null) {
                return;
            }

            $order->setRelation('items', SalesOrderItem::withoutGlobalScopes()->where('sales_order_id', $order->id)->get());
            $order->update(['inventory_status' => $this->reservations->refreshFromInventory($order)]);
        });
    }
}
