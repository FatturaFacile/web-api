<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response schemas of the valuation engine, landed costs and inventory book (cost layers, valuation configuration,
 * barcode scan and count session records).
 */
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

#[OA\Schema(
    schema: 'InventoryValuationConfigRow',
    title: 'Item Valuation Configuration',
    description: 'Effective valuation method of an item: item override, else category default, else weighted average',
    required: ['item_id', 'sku', 'effective_method', 'source']
)]
class InventoryValuationConfigRowSchema
{
    #[OA\Property(format: 'uuid')]
    public string $item_id;

    #[OA\Property(example: 'SKU-001')]
    public string $sku;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $category_id;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'], nullable: true)]
    public ?string $category_method;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'], nullable: true)]
    public ?string $item_method;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'])]
    public string $effective_method;

    #[OA\Property(enum: ['item', 'category', 'default'])]
    public string $source;
}

#[OA\Schema(
    schema: 'InventoryValuationCategory',
    title: 'Category Valuation Settings',
    required: ['id', 'valuation_method']
)]
class InventoryValuationCategorySchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'CAT-RAW')]
    public string $code;

    #[OA\Property(example: 'Raw materials')]
    public string $name;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'])]
    public string $valuation_method;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $inventory_account_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $cogs_account_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $ppv_account_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $landed_cost_clearing_account_id;
}

#[OA\Schema(
    schema: 'InventoryValuationItem',
    title: 'Item Valuation Settings',
    description: 'Response of PUT valuation/items/{id}; `revaluation` is set when on-hand stock was revalued',
    required: ['id', 'effective_method', 'source']
)]
class InventoryValuationItemSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'SKU-001')]
    public string $sku;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'], nullable: true)]
    public ?string $valuation_method;

    #[OA\Property(enum: ['fifo', 'weighted_average', 'standard_cost'])]
    public string $effective_method;

    #[OA\Property(enum: ['item', 'category', 'default'])]
    public string $source;

    #[OA\Property(example: '5.0000')]
    public string $standard_cost;

    #[OA\Property(example: '6.0000')]
    public string $moving_average_cost;

    #[OA\Property(example: '1.250', nullable: true)]
    public ?string $weight_kg;

    #[OA\Property(example: '0.0100', nullable: true)]
    public ?string $volume_m3;

    #[OA\Property(type: 'object', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'total_inventory_delta', type: 'string', example: '12.50'),
        new OA\Property(property: 'total_cogs_delta', type: 'string', example: '-12.50'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    ])]
    public ?array $revaluation;
}

#[OA\Schema(
    schema: 'InventoryCostLayer',
    title: 'Inventory Cost Layer',
    description: 'Receipt cost layer; FIFO depletes remaining_quantity, landed costs uplift landed_cost_unit',
    required: ['id', 'item_id', 'received_at', 'original_quantity', 'remaining_quantity', 'unit_cost', 'landed_cost_unit', 'effective_unit_cost']
)]
class InventoryCostLayerSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $item_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $lot_id;

    #[OA\Property(format: 'date-time')]
    public string $received_at;

    #[OA\Property(example: '10.0000')]
    public string $original_quantity;

    #[OA\Property(example: '6.0000')]
    public string $remaining_quantity;

    #[OA\Property(example: '5.0000')]
    public string $unit_cost;

    #[OA\Property(example: '5.0000')]
    public string $landed_cost_unit;

    #[OA\Property(example: '10.0000')]
    public string $effective_unit_cost;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $receipt_id;
}

#[OA\Schema(
    schema: 'InventoryLandedCostInput',
    title: 'Landed Cost Document Input',
    required: ['document_date', 'cost_type', 'allocation_key', 'currency', 'total_amount', 'receipt_ids']
)]
class InventoryLandedCostInputSchema
{
    #[OA\Property(format: 'date', example: '2026-11-15')]
    public string $document_date;

    #[OA\Property(enum: ['freight', 'customs_duty', 'insurance', 'brokerage', 'other'])]
    public string $cost_type;

    #[OA\Property(enum: ['value', 'weight', 'volume', 'quantity'])]
    public string $allocation_key;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(example: 300.00)]
    public float $total_amount;

    #[OA\Property(description: 'Optional rate to company currency (default 1)', example: 1)]
    public ?float $exchange_rate;

    #[OA\Property(format: 'uuid', nullable: true, description: 'Passive invoice reference (Mod 048)')]
    public ?string $supplier_invoice_id;

    #[OA\Property(nullable: true)]
    public ?string $notes;

    #[OA\Property(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))]
    public array $receipt_ids;
}

#[OA\Schema(
    schema: 'InventoryLandedCostAllocation',
    title: 'Landed Cost Allocation',
    required: ['receipt_id', 'item_id', 'basis_value', 'allocated_amount', 'to_inventory_amount', 'to_cogs_amount']
)]
class InventoryLandedCostAllocationSchema
{
    #[OA\Property(format: 'uuid')]
    public string $receipt_id;

    #[OA\Property(format: 'uuid')]
    public string $item_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $lot_id;

    #[OA\Property(example: '1000.000000')]
    public string $basis_value;

    #[OA\Property(example: '200.00')]
    public string $allocated_amount;

    #[OA\Property(example: '120.00')]
    public string $to_inventory_amount;

    #[OA\Property(example: '80.00')]
    public string $to_cogs_amount;

    #[OA\Property(example: '0.00', description: 'Standard-cost items: share expensed as purchase price variance')]
    public string $to_variance_amount;
}

#[OA\Schema(
    schema: 'InventoryLandedCostPreview',
    title: 'Landed Cost Allocation Preview',
    required: ['allocations', 'totals']
)]
class InventoryLandedCostPreviewSchema
{
    #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLandedCostAllocation'))]
    public array $allocations;

    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'allocated', type: 'string', example: '300.00'),
        new OA\Property(property: 'difference', type: 'string', example: '0.00'),
    ])]
    public array $totals;
}

#[OA\Schema(
    schema: 'InventoryLandedCostDocument',
    title: 'Landed Cost Document',
    required: ['id', 'number', 'status', 'cost_type', 'allocation_key', 'currency', 'total_amount', 'document_date', 'receipts', 'allocations']
)]
class InventoryLandedCostDocumentSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'LC-2026-00001')]
    public string $number;

    #[OA\Property(enum: ['draft', 'applied', 'reversed'])]
    public string $status;

    #[OA\Property(enum: ['freight', 'customs_duty', 'insurance', 'brokerage', 'other'])]
    public string $cost_type;

    #[OA\Property(enum: ['value', 'weight', 'volume', 'quantity'])]
    public string $allocation_key;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(example: '300.00')]
    public string $total_amount;

    #[OA\Property(format: 'date')]
    public string $document_date;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $supplier_invoice_id;

    #[OA\Property(type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'receipt_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'receipt_number', type: 'string', nullable: true),
    ]))]
    public array $receipts;

    #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLandedCostAllocation'))]
    public array $allocations;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $journal_entry_id;

    #[OA\Property(format: 'date-time', nullable: true)]
    public ?string $applied_at;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $reversal_of_id;

    #[OA\Property(type: 'object', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'total_inventory_delta', type: 'string', example: '30.00'),
        new OA\Property(property: 'total_cogs_delta', type: 'string', example: '20.00'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    ])]
    public ?array $revaluation;
}

#[OA\Schema(
    schema: 'InventoryBook',
    title: 'Inventory Book',
    description: 'Libro inventari: rows per item / lot / warehouse with GL reconciliation',
    required: ['meta', 'rows', 'totals', 'reconciliation']
)]
class InventoryBookSchema
{
    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'year', type: 'integer', example: 2026),
        new OA\Property(property: 'as_of', type: 'string', format: 'date'),
        new OA\Property(property: 'company_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'method_breakdown', type: 'object'),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
    ])]
    public array $meta;

    #[OA\Property(type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'uom', type: 'string'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'quantity', type: 'string', example: '6.0000'),
        new OA\Property(property: 'unit_cost', type: 'string', example: '10.0000'),
        new OA\Property(property: 'total_value', type: 'string', example: '60.00'),
        new OA\Property(property: 'valuation_method', type: 'string', enum: ['fifo', 'weighted_average', 'standard_cost']),
    ]))]
    public array $rows;

    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'quantity', type: 'string', example: '6.0000'),
        new OA\Property(property: 'total_value', type: 'string', example: '60.00'),
    ])]
    public array $totals;

    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'gl_inventory_balance', type: 'string', example: '60.00'),
        new OA\Property(property: 'difference', type: 'string', example: '0.00'),
    ])]
    public array $reconciliation;
}

#[OA\Schema(
    schema: 'InventoryBookExport',
    title: 'Inventory Book Export',
    description: 'Immutable, SHA-256 checksummed inventory book snapshot',
    required: ['id', 'year', 'as_of', 'checksum']
)]
class InventoryBookExportSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 2026)]
    public int $year;

    #[OA\Property(format: 'date')]
    public string $as_of;

    #[OA\Property(example: 42)]
    public int $rows_count;

    #[OA\Property(type: 'object')]
    public array $totals;

    #[OA\Property(type: 'object')]
    public array $reconciliation;

    #[OA\Property(example: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855')]
    public string $checksum;

    #[OA\Property(format: 'date-time')]
    public string $generated_at;
}
