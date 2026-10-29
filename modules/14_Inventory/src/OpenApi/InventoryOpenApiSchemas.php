<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryWarehouse',
    title: 'Inventory Warehouse',
    description: 'Warehouse facility, branch stockroom, or service vehicle storage location',
    required: ['id', 'tenant_id', 'code', 'name', 'type', 'is_active']
)]
class InventoryOpenApiSchemas
{
    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c')]
    public string $tenant_id;

    #[OA\Property(example: 'WH-MAIN')]
    public string $code;

    #[OA\Property(example: 'Milano Central Logistics Hub')]
    public string $name;

    #[OA\Property(example: 'standard', enum: ['standard', 'van', 'transit', 'quarantine', 'consignment'])]
    public string $type;

    #[OA\Property(example: true)]
    public bool $is_active;
}

#[OA\Schema(
    schema: 'InventoryWarehouseBin',
    title: 'Warehouse Bin / Location',
    description: 'Specific addressable location in a warehouse (Aisle, Rack, Shelf, Bin)',
    required: ['id', 'warehouse_id', 'code', 'bin_type']
)]
class InventoryWarehouseBinSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(example: 'BIN-01-A-03')]
    public string $code;

    #[OA\Property(example: 'standard', enum: ['standard', 'inbound', 'outbound', 'quarantine', 'transit'])]
    public string $bin_type;

    #[OA\Property(example: false)]
    public bool $is_locked;
}

#[OA\Schema(
    schema: 'InventoryStockItem',
    title: 'Stock Item / Product Master',
    description: 'Tracked product or material master record with valuation rules',
    required: ['id', 'sku', 'name', 'tracking_type', 'costing_method']
)]
class InventoryStockItemSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'SKU-ELEC-4090')]
    public string $sku;

    #[OA\Property(example: 'High Performance GPU Module')]
    public string $name;

    #[OA\Property(example: 'none', enum: ['none', 'lot', 'serial'])]
    public string $tracking_type;

    #[OA\Property(example: 'FIFO', enum: ['FIFO', 'AVCO', 'STANDARD'])]
    public string $costing_method;

    #[OA\Property(example: 450.00)]
    public float $current_average_cost;

    #[OA\Property(example: 10.0)]
    public float $reorder_point;

    #[OA\Property(example: 50.0)]
    public float $reorder_quantity;
}

#[OA\Schema(
    schema: 'InventoryStockQuant',
    title: 'Stock Quant',
    description: 'Atomic inventory balance at a specific location, lot, and serial',
    required: ['id', 'stock_item_id', 'warehouse_id', 'quantity', 'reserved_quantity']
)]
class InventoryStockQuantSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $stock_item_id;

    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $warehouse_bin_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $lot_id;

    #[OA\Property(example: 120.0)]
    public float $quantity;

    #[OA\Property(example: 20.0)]
    public float $reserved_quantity;

    #[OA\Property(example: 100.0)]
    public float $available_quantity;

    #[OA\Property(example: 45.00)]
    public float $unit_cost;
}

#[OA\Schema(
    schema: 'InventoryStockMovement',
    title: 'Stock Movement',
    description: 'Immutable double-entry inventory ledger entry',
    required: ['id', 'stock_item_id', 'movement_type', 'quantity', 'status']
)]
class InventoryStockMovementSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $stock_item_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $source_bin_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $destination_bin_id;

    #[OA\Property(example: 'internal_transfer', enum: ['receipt', 'internal_transfer', 'dispatch', 'adjustment', 'scrap', 'production_issue', 'production_receipt'])]
    public string $movement_type;

    #[OA\Property(example: 25.0)]
    public float $quantity;

    #[OA\Property(example: 45.00)]
    public float $unit_cost;

    #[OA\Property(example: 1125.00)]
    public float $total_cost;

    #[OA\Property(example: 'completed', enum: ['pending', 'completed', 'cancelled'])]
    public string $status;
}

#[OA\Schema(
    schema: 'InventoryStockReservation',
    title: 'Stock Reservation',
    description: 'Inventory allocation for sales, production orders, or projects',
    required: ['id', 'stock_item_id', 'warehouse_id', 'quantity_reserved', 'status']
)]
class InventoryStockReservationSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $stock_item_id;

    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(example: 15.0)]
    public float $quantity_reserved;

    #[OA\Property(example: 'active', enum: ['active', 'fulfilled', 'released', 'expired'])]
    public string $status;

    #[OA\Property(example: 'sales_order')]
    public string $reference_type;

    #[OA\Property(format: 'uuid')]
    public string $reference_id;
}

#[OA\Schema(
    schema: 'InventoryAdjustment',
    title: 'Inventory Adjustment / Write-Off',
    description: 'Physical inventory adjustment record with approval workflow integration',
    required: ['id', 'warehouse_id', 'adjustment_type', 'status']
)]
class InventoryAdjustmentSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(example: 'write_off', enum: ['cycle_count', 'damage', 'write_off', 'revaluation'])]
    public string $adjustment_type;

    #[OA\Property(example: 'pending_approval', enum: ['draft', 'pending_approval', 'approved', 'rejected'])]
    public string $status;

    #[OA\Property(example: 650.00)]
    public float $total_cost_impact;
}

#[OA\Schema(
    schema: 'InventoryCountSession',
    title: 'Cycle Count Session',
    description: 'Physical counting audit session for warehouse locations and bins',
    required: ['id', 'warehouse_id', 'status', 'start_date']
)]
class InventoryCountSessionSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(example: 'in_progress', enum: ['draft', 'in_progress', 'completed', 'cancelled'])]
    public string $status;

    #[OA\Property(example: '2026-10-28T08:00:00Z')]
    public string $start_date;

    #[OA\Property(example: 2)]
    public int $discrepancies_count;
}

#[OA\Schema(
    schema: 'InventoryBarcodeScan',
    title: 'Barcode Scan Result',
    description: 'Decoded item, serial, or lot details from mobile scanner input',
    required: ['barcode', 'entity_type', 'is_found']
)]
class InventoryBarcodeScanSchema
{
    #[OA\Property(example: '8001234567890')]
    public string $barcode;

    #[OA\Property(example: 'stock_item', enum: ['stock_item', 'lot', 'serial_number', 'warehouse_bin'])]
    public string $entity_type;

    #[OA\Property(example: true)]
    public bool $is_found;

    #[OA\Property(type: 'object')]
    public array $data;
}

#[OA\Schema(
    schema: 'InventoryValuationSummary',
    title: 'Valuation Balance Summary',
    description: 'Warehouse inventory valuation aggregated by item and costing method',
    required: ['warehouse_id', 'valuation_method', 'total_inventory_value']
)]
class InventoryValuationSummarySchema
{
    #[OA\Property(format: 'uuid')]
    public string $warehouse_id;

    #[OA\Property(example: 'FIFO')]
    public string $valuation_method;

    #[OA\Property(example: 184500.50)]
    public float $total_inventory_value;

    #[OA\Property(example: 450)]
    public int $total_active_skus;
}
