<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Services\Bom\BomWhereUsedService;
use Modules\Manufacturing\Support\Money;
use Modules\Plm\CQRS\Queries\GetWhereUsedImpactQuery;

/**
 * Multi-level Where-Used impact analyzer:
 * - Multi-level upward implosion (all parent sub-assemblies and finished goods)
 * - Open Purchase Orders (Ordini di Acquisto)
 * - Open Production Orders (Ordini di Produzione)
 * - Warehouse Stock & Lots (Giacenze di Magazzino)
 */
final readonly class GetWhereUsedImpactHandler
{
    public function __construct(private BomWhereUsedService $whereUsed) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetWhereUsedImpactQuery $query): array
    {
        $item = MfgItem::query()->where('tenant_id', $query->tenantId)->findOrFail($query->mfgItemId);
        $implosion = $this->whereUsed->implode($item, $query->date);

        $affectedItemIds = array_values(array_unique([
            (string) $item->id,
            ...array_map(static fn (array $p): string => (string) $p['parent_item_id'], $implosion['parents']),
        ]));

        return [
            ...$implosion,
            'item_name' => $item->name,
            'open_purchase_orders' => $this->openPurchaseOrders($query->tenantId, $affectedItemIds),
            'open_production_orders' => $this->openProductionOrders($query->tenantId, $affectedItemIds),
            'warehouse_stock_lots' => $this->warehouseStockAndLots($query->tenantId, $affectedItemIds),
        ];
    }

    /**
     * @param  list<string>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function openPurchaseOrders(string $tenantId, array $itemIds): array
    {
        $items = MfgItem::query()->where('tenant_id', $tenantId)->whereIn('id', $itemIds)->get();
        $productToItem = [];
        foreach ($items as $item) {
            if ($item->product_id !== null) {
                $productToItem[(string) $item->product_id] = $item;
            }
        }

        if ($productToItem === [] || ! Schema::hasTable('purchase_order_items')) {
            return [];
        }

        $rows = DB::table('purchase_order_items as l')
            ->join('purchase_orders as po', 'po.id', '=', 'l.purchase_order_id')
            ->where('po.tenant_id', $tenantId)
            ->whereNull('po.deleted_at')
            ->whereNull('l.deleted_at')
            ->whereIn('po.status', ['draft', 'pending_approval', 'approved', 'sent', 'confirmed', 'partially_received'])
            ->whereIn('l.product_id', array_keys($productToItem))
            ->select('po.id as purchase_order_id', 'po.order_number', 'po.status', 'po.date', 'po.delivery_due_date', 'l.product_id', 'l.quantity', 'l.quantity_received')
            ->orderByDesc('po.date')
            ->limit(100)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $item = $productToItem[(string) $row->product_id] ?? null;
            $openQty = Money::sub((string) $row->quantity, (string) ($row->quantity_received ?? '0'));
            if (! Money::isPositive($openQty)) {
                continue;
            }

            $result[] = [
                'purchase_order_id' => (string) $row->purchase_order_id,
                'order_number' => (string) $row->order_number,
                'status' => (string) $row->status,
                'item_code' => $item?->item_code,
                'item_name' => $item?->name,
                'open_quantity' => Money::qty($openQty),
                'delivery_due_date' => $row->delivery_due_date ?? $row->date,
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function openProductionOrders(string $tenantId, array $itemIds): array
    {
        $ordersByParent = MfgProductionOrder::query()
            ->with('mfgItem:id,item_code,name')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['planned', 'released', 'in_progress'])
            ->whereIn('mfg_item_id', $itemIds)
            ->get();

        $orderIdsFromLines = MfgProductionOrderLine::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('mfg_item_id', $itemIds)
            ->pluck('production_order_id')
            ->all();

        $ordersByLine = $orderIdsFromLines === []
            ? collect()
            : MfgProductionOrder::query()
                ->with('mfgItem:id,item_code,name')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['planned', 'released', 'in_progress'])
                ->whereIn('id', $orderIdsFromLines)
                ->get();

        $merged = $ordersByParent->merge($ordersByLine)->unique('id');
        $result = [];

        foreach ($merged as $order) {
            $remaining = Money::sub((string) $order->target_quantity, (string) $order->completed_quantity);
            $result[] = [
                'production_order_id' => (string) $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'item_code' => $order->mfgItem?->item_code,
                'item_name' => $order->mfgItem?->name,
                'target_quantity' => Money::qty((string) $order->target_quantity),
                'remaining_quantity' => Money::qty($remaining),
                'planned_end_date' => $order->planned_end_date?->toDateString(),
            ];
        }

        return $result;
    }

    /**
     * @param  list<string>  $itemIds
     * @return list<array<string, mixed>>
     */
    private function warehouseStockAndLots(string $tenantId, array $itemIds): array
    {
        $items = MfgItem::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $itemIds)
            ->whereNotNull('stock_item_id')
            ->get()
            ->keyBy('stock_item_id');

        if ($items->isEmpty()) {
            return [];
        }

        $stockItemIds = $items->keys()->all();
        $quants = StockQuant::withoutGlobalScopes()
            ->with(['lot', 'bin.rack.zone.warehouse'])
            ->where('tenant_id', $tenantId)
            ->whereIn('stock_item_id', $stockItemIds)
            ->whereNull('deleted_at')
            ->get();

        $result = [];
        foreach ($quants as $quant) {
            $mfgItem = $items->get($quant->stock_item_id);
            $lot = $quant->lot;
            $result[] = [
                'stock_quant_id' => (string) $quant->id,
                'mfg_item_id' => $mfgItem instanceof MfgItem ? (string) $mfgItem->id : null,
                'item_code' => $mfgItem?->item_code,
                'item_name' => $mfgItem?->name,
                'lot_id' => $lot instanceof StockLot ? (string) $lot->id : null,
                'lot_code' => $lot instanceof StockLot ? ($lot->getAttribute('lot_number') ?? $lot->getAttribute('code')) : null,
                'is_quarantined' => $lot instanceof StockLot ? (bool) $lot->getAttribute('is_quarantined') : false,
                'quantity_on_hand' => Money::qty((string) $quant->quantity_on_hand),
                'quantity_reserved' => Money::qty((string) $quant->quantity_reserved),
                'quantity_available' => Money::qty(Money::sub((string) $quant->quantity_on_hand, (string) $quant->quantity_reserved)),
            ];
        }

        return $result;
    }
}
