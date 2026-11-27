<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Product;
use DomainException;
use Modules\Inventory\Models\StockItem;
use Modules\Logistics\Services\WarehouseStockService;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Exceptions\SubcontractingException;
use Modules\Subcontracting\Models\SubcontractingOrder;

/**
 * The module's only door to stock for the movements no DDT performs (consumption, scrap, receipt of processed goods). Stock moves
 * through the Logistics warehouse stock service, i.e. the inventory ledger stays the single stock truth.
 */
final class SubcontractStockMover
{
    public function __construct(private readonly WarehouseStockService $stock) {}

    /** Takes goods out of a warehouse (consumed or scrapped by the job). */
    public function consume(SubcontractingOrder $order, string $productId, string $warehouseId, string $quantity, string $movementType, string $notes): void
    {
        $this->run(fn () => $this->stock->move($productId, $warehouseId, null, Money::qtyToFloat($quantity), $movementType, SubcontractingOrder::class, (string) $order->id, $notes));
    }

    /** Books goods into a warehouse at a given unit cost (null: the current moving average). */
    public function receive(SubcontractingOrder $order, string $productId, string $warehouseId, string $quantity, string $movementType, string $notes, ?string $unitCost = null): void
    {
        $cost = $unitCost !== null && Money::isPositive($unitCost) ? (float) Money::round($unitCost, 4) : null;
        $this->run(fn () => $this->stock->move($productId, null, $warehouseId, Money::qtyToFloat($quantity), $movementType, SubcontractingOrder::class, (string) $order->id, $notes, $cost));
    }

    /** Quantity of the product on hand in the warehouse. */
    public function onHand(string $warehouseId, string $productId): string
    {
        return Money::qty($this->stock->onHand($warehouseId, $productId));
    }

    /** Moving average cost of the product, else its standard cost, else the cost price of the product master. */
    public function unitCost(string $productId): string
    {
        $item = StockItem::query()->where('product_id', $productId)->first();

        foreach ([$item?->current_moving_average_cost, $item?->standard_cost, Product::query()->whereKey($productId)->value('cost_price')] as $candidate) {
            if ($candidate !== null && Money::isPositive((string) $candidate)) {
                return Money::round((string) $candidate, 6);
            }
        }

        return '0.000000';
    }

    private function run(callable $movement): void
    {
        try {
            $movement();
        } catch (DomainException $e) {
            throw new SubcontractingException('stock_movement_failed', 422, ['reason' => $e->getMessage()]);
        }
    }
}
