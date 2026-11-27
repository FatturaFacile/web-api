<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use App\Models\SalesOrderItem;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Support\Quantity;

/**
 * Demand seen by a warehouse: the open sales orders (committed demand) and the consumption rate of the last weeks (forecast of
 * point of sale and other uncommitted demand).
 */
final class DemandService
{
    /** Outbound movement types that count as customer demand (transfers, write-offs and consignment moves do not). */
    public const array DEMAND_MOVEMENT_TYPES = ['dispatch', 'pick', 'sale', 'sale_out', 'issue'];

    private const array OPEN_ORDER_STATUSES = ['confirmed', 'approved', 'in_production', 'in_fulfillment'];

    /**
     * Average daily consumption per warehouse and item over the look-back window.
     *
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     * @return array<string, string>
     */
    public function dailyRates(array $warehouseIds = [], array $itemIds = [], int $lookbackDays = 60): array
    {
        $lookbackDays = max(1, $lookbackDays);

        $rows = StockMovement::query()
            ->whereIn('movement_type', self::DEMAND_MOVEMENT_TYPES)
            ->where('executed_at', '>=', Carbon::now()->subDays($lookbackDays))
            ->whereNotNull('stock_item_id')
            ->when($warehouseIds !== [], static fn ($query) => $query->whereIn('warehouse_id', $warehouseIds))
            ->when($itemIds !== [], static fn ($query) => $query->whereIn('stock_item_id', $itemIds))
            ->groupBy('warehouse_id', 'stock_item_id')
            ->selectRaw('warehouse_id as wid, stock_item_id as iid, SUM(quantity) as qty')
            ->get();

        $rates = [];

        foreach ($rows as $row) {
            $rates[StockPositionService::key((string) $row->getAttribute('wid'), (string) $row->getAttribute('iid'))]
                = Quantity::div(Quantity::of($row->getAttribute('qty')), (string) $lookbackDays) ?? '0.0000';
        }

        return $rates;
    }

    /**
     * Open sales order quantities still to deliver, with the date they are wanted.
     *
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     * @return list<array{warehouse_id: string, stock_item_id: string, quantity: string, date: string}>
     */
    public function openSalesOrders(array $warehouseIds = [], array $itemIds = []): array
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        if ($tenantId === null) {
            return [];
        }

        $rows = SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('stock_items', 'stock_items.product_id', '=', 'sales_order_items.product_id')
            ->where('sales_orders.tenant_id', $tenantId)
            ->whereNull('sales_orders.deleted_at')
            ->whereNull('sales_order_items.deleted_at')
            ->whereNull('stock_items.deleted_at')
            ->whereIn('sales_orders.status', self::OPEN_ORDER_STATUSES)
            ->selectRaw('COALESCE(sales_order_items.warehouse_id, sales_orders.warehouse_id) as wid, stock_items.id as iid, sales_order_items.quantity as ordered, sales_order_items.quantity_delivered as delivered, sales_order_items.quantity_cancelled as cancelled, sales_order_items.requested_date as requested, sales_orders.due_date as due, sales_orders.date as order_date')
            ->get();

        $demand = [];

        foreach ($rows as $row) {
            $warehouseId = $row->getAttribute('wid');

            if ($warehouseId === null || ($warehouseIds !== [] && ! in_array((string) $warehouseId, $warehouseIds, true))) {
                continue;
            }

            if ($itemIds !== [] && ! in_array((string) $row->getAttribute('iid'), $itemIds, true)) {
                continue;
            }

            $remaining = Quantity::sub(Quantity::sub((string) $row->getAttribute('ordered'), (string) $row->getAttribute('delivered')), (string) $row->getAttribute('cancelled'));

            if (! Quantity::isPositive($remaining)) {
                continue;
            }

            $date = $row->getAttribute('requested') ?? $row->getAttribute('due') ?? $row->getAttribute('order_date');

            $demand[] = [
                'warehouse_id' => (string) $warehouseId,
                'stock_item_id' => (string) $row->getAttribute('iid'),
                'quantity' => $remaining,
                'date' => Carbon::parse((string) $date)->toDateString(),
            ];
        }

        return $demand;
    }

    /**
     * Committed sales order demand per warehouse and item due up to a date (overdue orders included).
     *
     * @param  list<string>  $warehouseIds
     * @param  list<string>  $itemIds
     * @return array<string, string>
     */
    public function committedUntil(Carbon $until, array $warehouseIds = [], array $itemIds = []): array
    {
        $totals = [];

        foreach ($this->openSalesOrders($warehouseIds, $itemIds) as $row) {
            if ($row['date'] > $until->toDateString()) {
                continue;
            }

            $key = StockPositionService::key($row['warehouse_id'], $row['stock_item_id']);
            $totals[$key] = Quantity::add($totals[$key] ?? '0', $row['quantity']);
        }

        return $totals;
    }
}
