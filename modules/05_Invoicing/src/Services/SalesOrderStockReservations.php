<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\Product;
use App\Services\Inventory\StockService;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Contracts\StockReservationServiceInterface;
use Modules\Inventory\DTOs\StockReservationDTO;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductStockItemResolver;
use Modules\Inventory\Services\StockAllocationPlanner;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderItem;

/**
 * Stock of the sales orders, through the public services of the inventory module only: availability to promise from the
 * quants an order may draw on (StockAllocationPlanner), reservations by StockReservationService (one per order line,
 * `reserved_for_type` = `sales_order_item`), released before the goods leave so the issue finds them available.
 *
 * Only products with inventory tracking are reserved; the other lines (services, non-stock items) need no stock. A line
 * reserves what is available up to its open quantity (ordered less delivered); what is missing stays a shortage the
 * availability check reports. `quantity_reserved` of the order line mirrors its active reservation.
 *
 * Open point for the warehouse (WMS) flow: picking and packing are run by the inventory module; when a pick list is
 * confirmed for an order line, the reservation of that line is what the pick consumes. Until the inventory module
 * exposes that link, the delivery note releases the reservation of its lines when it is dispatched.
 */
final readonly class SalesOrderStockReservations
{
    public const string ReservedForType = 'sales_order_item';

    public function __construct(
        private StockReservationServiceInterface $reservations,
        private ProductStockItemResolver $stockItems,
        private StockAllocationPlanner $planner,
        private StockService $stock,
    ) {}

    /**
     * The warehouse an order ships from: the one asked for, else the primary warehouse of the company.
     */
    public function warehouseFor(?string $warehouseId): ?Warehouse
    {
        if ($warehouseId !== null) {
            return Warehouse::query()->find($warehouseId);
        }

        $default = $this->stock->defaultWarehouse();

        return $default !== null ? Warehouse::query()->find($default->id) : null;
    }

    /**
     * Availability to promise of the open lines of the order in a warehouse: per line the open quantity, what is reserved
     * for it, what the warehouse can still give and the shortage.
     *
     * @return list<array{sales_order_item_id: string, product_id: ?string, description: string, stock_relevant: bool, quantity_ordered: string, quantity_delivered: string, quantity_open: string, quantity_reserved: string, quantity_available: string, quantity_promisable: string, shortage: string, fully_available: bool}>
     */
    public function availability(SalesOrder $order, ?Warehouse $warehouse): array
    {
        $tracked = $this->trackedProducts($order->items);
        $lines = [];

        foreach ($order->items as $item) {
            $open = $this->openQuantity($item);
            $reserved = bcadd(DocumentTotalsCalculator::decimal($item->quantity_reserved), '0', 4);
            $relevant = $item->product_id !== null && isset($tracked[(string) $item->product_id]);
            $available = $relevant && $warehouse !== null ? $this->availableIn((string) $item->product_id, (string) $order->tenant_id, $warehouse) : '0.0000';
            $promisable = $relevant ? self::min(Decimal::add($available, $reserved), $open) : $open;
            $shortage = Decimal::sub($open, $promisable);

            $lines[] = [
                'sales_order_item_id' => (string) $item->id,
                'product_id' => $item->product_id !== null ? (string) $item->product_id : null,
                'description' => (string) $item->description,
                'stock_relevant' => $relevant,
                'quantity_ordered' => bcadd(DocumentTotalsCalculator::decimal($item->quantity), '0', 4),
                'quantity_delivered' => bcadd(DocumentTotalsCalculator::decimal($item->quantity_delivered), '0', 4),
                'quantity_open' => $open,
                'quantity_reserved' => $reserved,
                'quantity_available' => $available,
                'quantity_promisable' => bcadd($promisable, '0', 4),
                'shortage' => bcadd(Decimal::isPositive($shortage) ? $shortage : '0', '0', 4),
                'fully_available' => ! Decimal::isPositive($shortage),
            ];
        }

        return $lines;
    }

    /**
     * Reserves the open quantity of every stock-relevant line, as far as the warehouse allows; a line reserved before is
     * reserved again from scratch. Returns the new inventory status of the order: `reserved`, `partially_reserved`,
     * `not_reserved` or `not_required` (no stock-relevant line).
     */
    public function reserve(SalesOrder $order, Warehouse $warehouse, ?string $userId, ?string $expiresAt = null): string
    {
        return DB::transaction(function () use ($order, $warehouse, $userId, $expiresAt): string {
            $tracked = $this->trackedProducts($order->items);
            $relevantLines = 0;
            $fullyReserved = 0;
            $anyReserved = false;

            foreach ($order->items as $item) {
                if ($item->product_id === null || ! isset($tracked[(string) $item->product_id])) {
                    continue;
                }

                $relevantLines++;
                $this->releaseLine($item);
                $open = $this->openQuantity($item);
                $stockItem = $this->stockItems->find((string) $item->product_id, (string) $order->tenant_id);
                $reserved = '0';

                if ($stockItem !== null && Decimal::isPositive($open)) {
                    $available = $this->availableIn((string) $item->product_id, (string) $order->tenant_id, $warehouse);
                    $reserved = self::min($available, $open);

                    if (Decimal::isPositive($reserved)) {
                        $this->reservations->allocate(new StockReservationDTO(
                            stockItemId: (string) $stockItem->id,
                            warehouseId: (string) $warehouse->id,
                            reservedForType: self::ReservedForType,
                            reservedForId: (string) $item->id,
                            quantity: (float) $reserved,
                            expiresAt: $expiresAt,
                            userId: $userId,
                        ));
                    }
                }

                $item->update(['quantity_reserved' => bcadd($reserved, '0', 4)]);
                $anyReserved = $anyReserved || Decimal::isPositive($reserved);

                if (Decimal::compare($reserved, $open) >= 0) {
                    $fullyReserved++;
                }
            }

            return match (true) {
                $relevantLines === 0 => 'not_required',
                $fullyReserved === $relevantLines => 'reserved',
                $anyReserved => 'partially_reserved',
                default => 'not_reserved',
            };
        });
    }

    /**
     * Releases the active reservations of the given lines of the order (all lines when none is given).
     *
     * @param  list<string>|null  $itemIds
     */
    public function release(SalesOrder $order, ?array $itemIds = null): void
    {
        DB::transaction(function () use ($order, $itemIds): void {
            foreach ($order->items as $item) {
                if ($itemIds !== null && ! in_array((string) $item->id, $itemIds, true)) {
                    continue;
                }

                $this->releaseLine($item);
                $item->update(['quantity_reserved' => '0']);
            }
        });
    }

    private function releaseLine(SalesOrderItem $item): void
    {
        $active = StockReservation::query()
            ->where('reserved_for_type', self::ReservedForType)
            ->where('reserved_for_id', (string) $item->id)
            ->where('status', 'active')
            ->get();

        foreach ($active as $reservation) {
            $this->reservations->release($reservation);
        }
    }

    /**
     * Ordered less delivered, never negative.
     */
    private function openQuantity(SalesOrderItem $item): string
    {
        $open = Decimal::sub(DocumentTotalsCalculator::decimal($item->quantity), DocumentTotalsCalculator::decimal($item->quantity_delivered));

        return bcadd(Decimal::isPositive($open) ? $open : '0', '0', 4);
    }

    /**
     * What the eligible quants of the product in the warehouse can still give (on hand less reserved).
     */
    private function availableIn(string $productId, string $tenantId, Warehouse $warehouse): string
    {
        $stockItem = $this->stockItems->find($productId, $tenantId);

        if ($stockItem === null) {
            return '0.0000';
        }

        $available = array_reduce(
            $this->planner->availability($stockItem, $warehouse),
            static fn (string $sum, array $quant): string => Decimal::add($sum, $quant['quantity_available']),
            '0'
        );

        return bcadd($available, '0', 4);
    }

    /**
     * Products of the lines that track inventory, keyed by id.
     *
     * @param  Collection<int, SalesOrderItem>  $items
     * @return array<string, bool>
     */
    private function trackedProducts(Collection $items): array
    {
        $ids = $items->pluck('product_id')->filter()->map(static fn ($id): string => (string) $id)->unique()->values()->all();

        return Product::query()->whereIn('id', $ids)->where('track_inventory', true)->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(string) $id => true])->all();
    }

    private static function min(string $left, string $right): string
    {
        return Decimal::compare($left, $right) <= 0 ? $left : $right;
    }
}
