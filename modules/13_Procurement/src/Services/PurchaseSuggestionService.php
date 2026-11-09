<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockReorderRule;
use Modules\Inventory\Services\ProductStockItemResolver;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseSuggestion;
use Modules\Procurement\Support\Decimal;

/**
 * Purchase suggestions from inventory reorder rules (available stock, as the inventory module defines it through
 * InventoryReplenishmentServiceInterface::availableInWarehouse so quarantine and transit zones and quarantined lots never
 * count, plus on-order stock at or below the reorder point:
 * order the economic quantity, or up to the maximum level when more is missing) and from MRP planned purchase orders.
 * Each suggestion gets the cheapest valid supplier offer for the item (the preferred supplier of the rule when it has
 * one), and open suggestions are converted into one draft purchase order per supplier.
 */
final readonly class PurchaseSuggestionService
{
    /** Source of the suggestions made for a sales order line (buy to order, drop-ship). */
    public const string SalesOrderLineSource = 'sales_order_line';

    public function __construct(
        private SupplierPriceService $prices,
        private ProcurementService $procurement,
        private InventoryReplenishmentServiceInterface $inventory,
        private ProductStockItemResolver $stockItems,
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

                $position = Decimal::add($this->inventory->availableInWarehouse($item, (string) $rule->warehouse_id), $this->onOrder($tenantId, $item), 4);

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
     * A purchase suggestion for the open quantity (ordered less delivered) of a sales order line, for the lines a
     * company buys to order or has the supplier ship to the customer (drop-ship). The line must belong to an order of
     * the tenant and sell a product with a stock item; the suggestion gets the cheapest valid supplier offer for its SKU
     * and the order's due date as required-by date. Asking twice returns the open suggestion of the line instead of a
     * second one (source `sales_order_line`).
     *
     * @throws ProcurementRuleViolation when the line is not an open line of an order of the tenant, or its product has
     *                                  no stock item
     */
    public function suggestForSalesOrderLine(string $tenantId, string $salesOrderItemId): PurchaseSuggestion
    {
        return DB::transaction(function () use ($tenantId, $salesOrderItemId): PurchaseSuggestion {
            $line = SalesOrderItem::withoutGlobalScopes()->whereKey($salesOrderItemId)->first();
            $order = $line !== null
                ? SalesOrder::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($line->sales_order_id)->whereNull('deleted_at')->first()
                : null;

            if ($line === null || $order === null) {
                throw new ProcurementRuleViolation('The sales order line does not exist in the active company.', 'sales_order_item_id');
            }

            $existing = PurchaseSuggestion::query()
                ->where('tenant_id', $tenantId)
                ->where('source', self::SalesOrderLineSource)
                ->where('source_id', (string) $line->id)
                ->where('status', 'open')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $item = $line->product_id !== null ? $this->stockItems->find((string) $line->product_id, $tenantId) : null;

            if ($item === null) {
                throw new ProcurementRuleViolation('The product of the sales order line has no stock item to buy.', 'sales_order_item_id');
            }

            $open = Decimal::sub(Decimal::of((string) $line->quantity, 4), Decimal::of((string) ($line->quantity_delivered ?? '0'), 4), 4);

            if (! Decimal::isPositive($open)) {
                throw new ProcurementRuleViolation('The sales order line has no open quantity left to buy.', 'sales_order_item_id');
            }

            $dueDate = $order->getRawOriginal('due_date');

            return $this->suggest($tenantId, self::SalesOrderLineSource, (string) $line->id, $item, null, $open, $dueDate !== null ? substr((string) $dueDate, 0, 10) : null, null, now()->toDateString());
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
