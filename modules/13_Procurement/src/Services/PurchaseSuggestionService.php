<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseSuggestion;
use Modules\Procurement\Support\Decimal;

/**
 * Purchase suggestions from inventory reorder rules (available plus on-order stock at or below the reorder point:
 * order the economic quantity, or up to the maximum level when more is missing) and from MRP planned purchase orders.
 * Each suggestion gets the cheapest valid supplier offer for the item (the preferred supplier of the rule when it has
 * one), and open suggestions are converted into one draft purchase order per supplier.
 */
final readonly class PurchaseSuggestionService
{
    public function __construct(
        private SupplierPriceService $prices,
        private ProcurementService $procurement,
    ) {}

    /**
     * Generates the missing suggestions of the tenant; returns the new ones.
     *
     * @return Collection<int, PurchaseSuggestion>
     */
    public function generate(string $tenantId): Collection
    {
        return DB::transaction(function () use ($tenantId): Collection {
            $created = collect();
            $today = now()->toDateString();

            foreach (StockReorderRule::query()->where('tenant_id', $tenantId)->get() as $rule) {
                $item = StockItem::query()->find($rule->stock_item_id);

                if ($item === null || $this->hasOpen($tenantId, 'reorder_rule', (string) $rule->id)) {
                    continue;
                }

                $position = Decimal::add($this->availableIn($item, (string) $rule->warehouse_id), $this->onOrder($tenantId, $item), 4);

                if (Decimal::cmp($position, (string) $rule->reorder_point, 4) > 0) {
                    continue;
                }

                $quantity = Decimal::max((string) $rule->economic_order_quantity, Decimal::sub((string) $rule->max_stock_level, $position, 4), 4);

                if (Decimal::isPositive($quantity)) {
                    $created->push($this->suggest($tenantId, 'reorder_rule', (string) $rule->id, $item, (string) $rule->warehouse_id, $quantity, now()->addDays((int) $rule->lead_time_days)->toDateString(), $rule->preferred_supplier_id !== null ? (string) $rule->preferred_supplier_id : null, $today));
                }
            }

            $planned = DB::table('mfg_mrp_planned_orders as p')
                ->join('mfg_items as i', 'i.id', '=', 'p.mfg_item_id')
                ->where('p.tenant_id', $tenantId)
                ->where('p.order_type', 'buy')
                ->where('p.status', 'planned')
                ->whereNull('p.deleted_at')
                ->whereNotNull('i.stock_item_id')
                ->select(['p.id', 'p.quantity', 'p.due_date', 'i.stock_item_id'])
                ->get();

            foreach ($planned as $order) {
                $item = StockItem::query()->find($order->stock_item_id);

                if ($item === null || $this->hasOpen($tenantId, 'mrp', (string) $order->id)) {
                    continue;
                }

                $created->push($this->suggest($tenantId, 'mrp', (string) $order->id, $item, null, Decimal::of((string) $order->quantity, 4), substr((string) $order->due_date, 0, 10), null, $today));
            }

            return $created;
        });
    }

    /**
     * Converts open suggestions into draft purchase orders, one per supplier.
     *
     * @param  list<string>  $suggestionIds
     * @return Collection<int, PurchaseOrder>
     *
     * @throws ProcurementRuleViolation when a suggestion is not open or has no supplier offer
     */
    public function convert(string $tenantId, array $suggestionIds, ?string $userId): Collection
    {
        return DB::transaction(function () use ($tenantId, $suggestionIds, $userId): Collection {
            $suggestions = PurchaseSuggestion::query()->whereIn('id', array_unique($suggestionIds))->lockForUpdate()->get();

            if ($suggestions->count() !== count(array_unique($suggestionIds))) {
                throw new ProcurementRuleViolation('A suggestion does not exist in the active company.', 'suggestion_ids');
            }

            foreach ($suggestions as $suggestion) {
                if ($suggestion->status !== 'open' || $suggestion->supplier_id === null) {
                    throw new ProcurementRuleViolation('Only open suggestions with a supplier offer can be ordered.', 'suggestion_ids');
                }
            }

            return $suggestions->groupBy('supplier_id')->map(function (Collection $group, string $supplierId) use ($tenantId, $userId): PurchaseOrder {
                $order = $this->procurement->createPurchaseOrder(new CreatePurchaseOrderDTO(
                    supplierId: $supplierId,
                    orderDate: now()->toDateString(),
                    expectedDeliveryDate: (string) ($group->min(static fn (PurchaseSuggestion $suggestion): ?string => $suggestion->required_by_date?->toDateString()) ?? now()->addDays(7)->toDateString()),
                    lines: $group->map(static fn (PurchaseSuggestion $suggestion): array => [
                        'description' => (string) $suggestion->description,
                        'quantity_ordered' => (string) $suggestion->quantity,
                        'uom' => (string) $suggestion->uom,
                        'supplier_catalog_id' => $suggestion->supplier_catalog_id,
                    ])->values()->all(),
                    notes: 'Generated from purchase suggestions',
                    tenantId: $tenantId,
                    creatorUserId: $userId,
                ));

                PurchaseSuggestion::query()->whereIn('id', $group->pluck('id'))->update(['status' => 'converted', 'purchase_order_id' => $order->id]);

                return $order;
            })->values();
        });
    }

    /**
     * Dismisses an open suggestion.
     */
    public function dismiss(PurchaseSuggestion $suggestion): PurchaseSuggestion
    {
        if ($suggestion->status !== 'open') {
            throw new ProcurementRuleViolation('Only an open suggestion can be dismissed.', 'status');
        }

        $suggestion->update(['status' => 'dismissed']);

        return $suggestion;
    }

    private function suggest(string $tenantId, string $source, string $sourceId, StockItem $item, ?string $warehouseId, string $quantity, ?string $requiredBy, ?string $preferredSupplierId, string $today): PurchaseSuggestion
    {
        $offers = $this->prices->offersForSku((string) $item->sku, $quantity, $today);
        $offer = $offers->firstWhere('supplier_id', $preferredSupplierId) ?? $offers->first();

        return PurchaseSuggestion::create([
            'tenant_id' => $tenantId,
            'source' => $source,
            'source_id' => $sourceId,
            'stock_item_id' => $item->id,
            'warehouse_id' => $warehouseId,
            'supplier_id' => $offer['supplier_id'] ?? null,
            'supplier_catalog_id' => $offer['supplier_catalog_id'] ?? null,
            'description' => (string) ($item->name ?? $item->sku),
            'quantity' => $quantity,
            'uom' => (string) ($item->uom ?? 'PCE'),
            'unit_price' => $offer['unit_price'] ?? null,
            'required_by_date' => $requiredBy,
            'status' => 'open',
        ]);
    }

    private function hasOpen(string $tenantId, string $source, string $sourceId): bool
    {
        return PurchaseSuggestion::query()->where('tenant_id', $tenantId)->where('source', $source)->where('source_id', $sourceId)->where('status', 'open')->exists();
    }

    /**
     * Available stock (on hand less reserved) of the item in the bins of the warehouse.
     */
    private function availableIn(StockItem $item, string $warehouseId): string
    {
        $available = DB::table('stock_quants as q')
            ->join('warehouse_bins as b', 'b.id', '=', 'q.warehouse_bin_id')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->where('q.stock_item_id', $item->id)
            ->where('z.warehouse_id', $warehouseId)
            ->whereNull('q.deleted_at')
            ->selectRaw('COALESCE(SUM(q.quantity_on_hand - q.quantity_reserved), 0) as available')
            ->value('available');

        return Decimal::of((string) $available, 4);
    }

    /**
     * Quantity of the item on open purchase orders (catalogue entries with the SKU of the item).
     */
    private function onOrder(string $tenantId, StockItem $item): string
    {
        $open = DB::table('purchase_order_lines as l')
            ->join('purchase_orders as po', 'po.id', '=', 'l.purchase_order_id')
            ->join('supplier_catalogs as c', 'c.id', '=', 'l.supplier_catalog_id')
            ->where('po.tenant_id', $tenantId)
            ->whereNull('po.deleted_at')
            ->whereNull('l.deleted_at')
            ->where('c.internal_sku', $item->sku)
            ->whereIn('po.status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::PendingApproval->value, PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->selectRaw('COALESCE(SUM(GREATEST(l.quantity_ordered - l.quantity_cancelled - l.quantity_received, 0)), 0) as open_quantity')
            ->value('open_quantity');

        return Decimal::of((string) $open, 4);
    }
}
