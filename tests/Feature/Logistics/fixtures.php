<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\WarehouseLocationService;

function logisticsTenant(string $name = 'Alfa Logistica'): Tenant
{
    $tenant = Tenant::create(['name' => $name, 'legal_name' => $name.' SA', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($tenant);

    return $tenant;
}

function logisticsUser(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function logisticsWarehouse(array $attributes = []): Warehouse
{
    return Warehouse::create($attributes + ['code' => 'WH-'.Str::random(6), 'name' => 'Magazzino', 'warehouse_type' => 'standard', 'is_active' => true]);
}

function logisticsBin(Warehouse $warehouse, string $zoneType = 'storage', string $aisle = 'A1', string $rack = 'R1', string $bin = 'B1', ?int $pickSequence = null, string $zone = 'Z1'): WarehouseBin
{
    return app(WarehouseLocationService::class)->addBin($warehouse, [
        'zone_code' => $zone.$zoneType,
        'zone_type' => $zoneType,
        'aisle_code' => $aisle,
        'rack_code' => $rack,
        'bin_code' => $bin,
        'barcode' => 'BIN-'.Str::random(10),
        'pick_sequence' => $pickSequence,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function logisticsItem(array $attributes = []): StockItem
{
    return StockItem::factory()->create($attributes + ['sku' => 'SKU-'.Str::random(8), 'tracking_type' => 'lot']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function logisticsLot(StockItem $item, array $attributes = []): StockLot
{
    return StockLot::create($attributes + ['stock_item_id' => $item->id, 'lot_number' => 'LOT-'.Str::random(8)]);
}

/**
 * A receipt (no source), a delivery (no destination) or a relocation booked through the movement service.
 */
function logisticsMove(StockItem $item, ?WarehouseBin $source, ?WarehouseBin $destination, int|float $quantity, ?StockLot $lot = null): void
{
    app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $item->id,
        sourceBinId: $source?->id,
        destinationBinId: $destination?->id,
        quantity: (float) $quantity,
        movementType: $source === null ? 'receipt' : ($destination === null ? 'customer_delivery' : 'INTERNAL_TRANSFER'),
        lotId: $lot?->id,
    ));
}

function logisticsQuant(WarehouseBin $bin, StockItem $item, string $quantity, ?StockLot $lot = null): StockQuant
{
    return StockQuant::create([
        'warehouse_bin_id' => $bin->id,
        'stock_item_id' => $item->id,
        'lot_id' => $lot?->id,
        'quantity_on_hand' => $quantity,
        'quantity_reserved' => '0',
    ]);
}
