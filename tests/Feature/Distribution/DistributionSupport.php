<?php

declare(strict_types=1);

use Modules\Distribution\Models\DistributionLane;
use Modules\Distribution\Models\ReplenishmentRule;
use Modules\Distribution\Services\NetworkService;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;

require_once __DIR__.'/../Inventory/Wms/wms_fixtures.php';

/**
 * A warehouse with its network profile and one storage bin.
 *
 * @return array{0: Warehouse, 1: WarehouseBin}
 */
function drpWarehouse(string $code, string $role = 'internal', string $type = 'standard', string $owner = 'company'): array
{
    $warehouse = logisticsWarehouse(['code' => $code, 'name' => $code, 'warehouse_type' => $type, 'legal_owner_type' => $owner]);
    app(NetworkService::class)->saveProfile($warehouse, ['role' => $role]);

    return [$warehouse, logisticsBin($warehouse)];
}

/**
 * An item tracked by quantity only, valued at a moving average cost of 10.
 */
function drpItem(string $cost = '10'): StockItem
{
    return logisticsItem(['tracking_type' => 'none', 'current_moving_average_cost' => $cost, 'standard_cost' => $cost]);
}

/**
 * Customer demand booked through the movement service (a dispatch out of a bin), so the consumption forecast sees it.
 */
function drpDispatch(WarehouseBin $bin, StockItem $item, string $quantity): void
{
    app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $item->id,
        sourceBinId: (string) $bin->id,
        destinationBinId: null,
        quantity: (float) $quantity,
        movementType: 'dispatch',
    ));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function drpRule(Warehouse $warehouse, StockItem $item, ?Warehouse $source, array $attributes = []): ReplenishmentRule
{
    return ReplenishmentRule::create($attributes + [
        'warehouse_id' => $warehouse->id,
        'stock_item_id' => $item->id,
        'source_warehouse_id' => $source?->id,
        'policy' => 'min_max',
        'safety_stock' => 0,
        'reorder_point' => 0,
        'max_stock' => 0,
        'lead_time_days' => 1,
    ]);
}

function drpLane(Warehouse $source, Warehouse $destination, int $days = 1, string $mode = 'road'): DistributionLane
{
    return DistributionLane::create([
        'source_warehouse_id' => $source->id,
        'destination_warehouse_id' => $destination->id,
        'lead_time_days' => $days,
        'transport_mode' => $mode,
    ]);
}

/** Stock on hand of an item in one bin, as a 4 decimals string. */
function drpOnHand(WarehouseBin $bin, StockItem $item): string
{
    return wmsOnHand($bin, $item);
}

function drpReserve(WarehouseBin $bin, StockItem $item, string $quantity): void
{
    StockQuant::query()->where('warehouse_bin_id', $bin->id)->where('stock_item_id', $item->id)->update(['quantity_reserved' => $quantity]);
}
