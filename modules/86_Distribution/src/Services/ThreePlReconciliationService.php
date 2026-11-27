<?php

declare(strict_types=1);

namespace Modules\Distribution\Services;

use Illuminate\Support\Carbon;
use Modules\Distribution\Domain\Enums\WarehouseRole;
use Modules\Distribution\Models\ThreePlReconciliation;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Support\Quantity;

/**
 * Compares the stock the system holds at a third-party logistics warehouse with the quantities the provider reports and keeps
 * the discrepancies, so they can be investigated and adjusted by an inventory count.
 */
final class ThreePlReconciliationService
{
    public function __construct(
        private readonly StockPositionService $positions,
        private readonly NetworkService $network,
    ) {}

    /**
     * @param  list<array{stock_item_id: string, external_quantity: string|int|float}>  $lines
     */
    public function reconcile(Warehouse $warehouse, array $lines, ?string $externalReference): ThreePlReconciliation
    {
        if ($this->network->roleOf($warehouse) !== WarehouseRole::ThirdParty3pl) {
            throw new StockRuleViolation("Warehouse [{$warehouse->name}] is not a third-party logistics warehouse.");
        }

        $itemIds = array_map(static fn (array $line): string => $line['stock_item_id'], $lines);
        $system = $this->positions->onHand([(string) $warehouse->id], $itemIds);
        $items = StockItem::query()->whereIn('id', $itemIds)->get()->keyBy('id');
        $rows = [];
        $balanced = true;

        foreach ($lines as $line) {
            $item = $items->get($line['stock_item_id']);
            $systemQuantity = $system[StockPositionService::key((string) $warehouse->id, $line['stock_item_id'])]['on_hand'] ?? '0.0000';
            $external = Quantity::of($line['external_quantity']);
            $difference = Quantity::sub($external, $systemQuantity);
            $balanced = $balanced && Quantity::isZero($difference);

            $rows[] = [
                'stock_item_id' => $line['stock_item_id'],
                'sku' => (string) $item?->sku,
                'name' => (string) $item?->name,
                'system_quantity' => $systemQuantity,
                'external_quantity' => $external,
                'difference' => $difference,
            ];
        }

        return ThreePlReconciliation::create([
            'warehouse_id' => $warehouse->id,
            'external_reference' => $externalReference,
            'status' => $balanced ? 'balanced' : 'discrepancy',
            'reconciled_at' => Carbon::now(),
            'lines' => $rows,
        ])->load('warehouse');
    }
}
