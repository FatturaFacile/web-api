<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Carbon\CarbonImmutable;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Invoicing\Events\ShortageDetectedForProcurementEvent;
use Modules\Invoicing\Events\ShortageDetectedForProductionEvent;
use Modules\Invoicing\Models\ReplenishmentRequest;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Manufacturing\Models\MfgItem;

/**
 * Pegs the shortage of a confirmed sales order to a replenishment: a manufactured product (make policy of its
 * manufacturing item) to a production order request, any other to a purchase requisition request. The request is a
 * structured `replenishment_requests` row the planners convert into the purchasing or manufacturing document; the
 * shortage event is dispatched for the modules that react to it. The order line stores the reference for end-to-end
 * traceability. A line is replenished once (`replenishment_triggered`).
 */
final class ReplenishmentTriggerService
{
    public const string PurchaseRequisition = 'purchase_requisition';

    public const string ProductionOrder = 'production_order';

    public function __construct(
        private readonly SalesOrderStockReservations $reservations,
        private readonly AtpCtpCalculationService $atp,
        private readonly EventDispatcherInterface $events,
        private readonly SalesOrderAuditTrail $audit,
    ) {}

    /**
     * @return list<ReplenishmentRequest> the requests created by this call
     */
    public function generateForOrder(SalesOrder $order, ?string $userId = null): array
    {
        return DB::transaction(function () use ($order, $userId): array {
            $order->load('items');
            $warehouse = $this->reservations->warehouseFor($order->warehouse_id);
            $created = [];

            foreach ($this->reservations->availability($order, $warehouse) as $line) {
                if ($line['stock_relevant'] === false || bccomp($line['shortage'], '0', 4) <= 0 || $line['product_id'] === null) {
                    continue;
                }

                /** @var SalesOrderItem $item */
                $item = $order->items->firstWhere('id', $line['sales_order_item_id']);

                if ($item->replenishment_triggered) {
                    continue;
                }

                $created[] = $this->peg($order, $item, $line['shortage'], $warehouse?->id, $userId);
            }

            return $created;
        });
    }

    private function peg(SalesOrder $order, SalesOrderItem $item, string $shortage, ?string $warehouseId, ?string $userId): ReplenishmentRequest
    {
        $productId = (string) $item->product_id;
        $type = $this->typeFor((string) $order->tenant_id, $productId);
        $check = $this->atp->check((string) $order->tenant_id, $productId, $shortage, $warehouseId, null);
        $neededBy = $check['ctp_estimated_date'] ?? $item->requested_date?->toDateString() ?? $order->requested_delivery_date?->toDateString() ?? CarbonImmutable::now()->toDateString();

        $request = ReplenishmentRequest::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $order->tenant_id,
            'sales_order_id' => (string) $order->id,
            'sales_order_item_id' => (string) $item->id,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'request_type' => $type,
            'quantity' => $shortage,
            'needed_by' => $neededBy,
            'status' => 'open',
            'created_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
        ]);

        $item->update([
            'replenishment_triggered' => true,
            'replenishment_reference_type' => $type,
            'replenishment_reference_id' => (string) $request->id,
        ]);

        $details = ['replenishment_request_id' => (string) $request->id, 'sales_order_item_id' => (string) $item->id, 'product_id' => $productId, 'quantity' => $shortage, 'needed_by' => $neededBy];
        $event = $type === self::ProductionOrder
            ? new ShortageDetectedForProductionEvent((string) $order->id, (string) $order->tenant_id, (string) $order->tenant_id, $details)
            : new ShortageDetectedForProcurementEvent((string) $order->id, (string) $order->tenant_id, (string) $order->tenant_id, $details);
        $this->events->publishAndDispatch($event);

        $this->audit->record($order, 'replenishment_triggered', $details + ['request_type' => $type], $userId, null, null, (string) $item->id);

        return $request;
    }

    private function typeFor(string $tenantId, string $productId): string
    {
        $mfgItem = MfgItem::query()->where('tenant_id', $tenantId)->where('product_id', $productId)->first();

        return $mfgItem !== null && $mfgItem->procurementType() === 'make' ? self::ProductionOrder : self::PurchaseRequisition;
    }
}
