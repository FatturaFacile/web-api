<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response schemas of the stock master data and ledger records: warehouses, bins, stock items, quants, movements,
 * reservations and adjustments, with the pagination envelope of their lists.
 *
 * These endpoints answer with the bare resource (no success/message envelope) and their lists with the Laravel
 * paginator document: data, links and meta. Quantities and costs of these resources are JSON numbers.
 */
#[OA\Schema(schema: 'InventoryPaginationLinks', description: 'Links of the paginator; null at the first and last page', properties: [
    new OA\Property(property: 'first', type: 'string', nullable: true),
    new OA\Property(property: 'last', type: 'string', nullable: true),
    new OA\Property(property: 'prev', type: 'string', nullable: true),
    new OA\Property(property: 'next', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'InventoryPaginationMeta', description: 'Meta of the Laravel paginator', properties: [
    new OA\Property(property: 'current_page', type: 'integer'),
    new OA\Property(property: 'from', type: 'integer', nullable: true),
    new OA\Property(property: 'last_page', type: 'integer'),
    new OA\Property(property: 'links', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'url', type: 'string', nullable: true),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'active', type: 'boolean'),
    ])),
    new OA\Property(property: 'path', type: 'string'),
    new OA\Property(property: 'per_page', type: 'integer'),
    new OA\Property(property: 'to', type: 'integer', nullable: true),
    new OA\Property(property: 'total', type: 'integer'),
])]
#[OA\Schema(schema: 'InventoryWarehouse', description: 'Warehouse facility, branch stockroom or service vehicle storage', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'WH-MAIN'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'type', type: 'string', nullable: true, description: 'Stored warehouse_type'),
    new OA\Property(property: 'picking_strategy', type: 'string', nullable: true),
    new OA\Property(property: 'is_virtual', type: 'boolean'),
    new OA\Property(property: 'owner_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'address', type: 'string', nullable: true),
    new OA\Property(property: 'city', type: 'string', nullable: true),
    new OA\Property(property: 'postal_code', type: 'string', nullable: true),
    new OA\Property(property: 'country', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'zones', type: 'array', items: new OA\Items(type: 'object'), description: 'Only when the zones are loaded'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'InventoryWarehouseList', description: 'Paginated warehouses', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryWarehouse')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryWarehouseBin', description: 'Addressable location of a warehouse', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_rack_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'code', type: 'string', example: 'BIN-01-A-03'),
    new OA\Property(property: 'barcode', type: 'string', nullable: true),
    new OA\Property(property: 'bin_type', type: 'string'),
    new OA\Property(property: 'max_weight_kg', type: 'number'),
    new OA\Property(property: 'max_volume_cbm', type: 'number'),
    new OA\Property(property: 'is_quarantine', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'rack', type: 'object', description: 'Only when loaded'),
    new OA\Property(property: 'quants', type: 'array', items: new OA\Items(type: 'object'), description: 'Only when loaded'),
])]
#[OA\Schema(schema: 'InventoryStockItem', description: 'Stock item (SKU master). total_on_hand is the quantity over every bin and total_available is on hand less reserved.', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sku', type: 'string', example: 'PMP-IND-01'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'uom', type: 'string', example: 'PCE'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'barcode_ean13', type: 'string', nullable: true),
    new OA\Property(property: 'barcode_custom', type: 'string', nullable: true),
    new OA\Property(property: 'tracking_type', type: 'string', enum: ['none', 'lot', 'serial']),
    new OA\Property(property: 'standard_cost', type: 'number'),
    new OA\Property(property: 'moving_average_cost', type: 'number'),
    new OA\Property(property: 'weight_kg', type: 'number'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'picking_strategy', type: 'string', nullable: true),
    new OA\Property(property: 'shelf_life_days', type: 'integer', nullable: true),
    new OA\Property(property: 'min_remaining_shelf_life_days', type: 'integer', nullable: true),
    new OA\Property(property: 'net_weight_kg', type: 'string', nullable: true, description: 'Decimal string as stored'),
    new OA\Property(property: 'volume_m3', type: 'string', nullable: true, description: 'Decimal string as stored'),
    new OA\Property(property: 'units_per_carton', type: 'integer', nullable: true),
    new OA\Property(property: 'cartons_per_pallet', type: 'integer', nullable: true),
    new OA\Property(property: 'carton_tare_kg', type: 'string', nullable: true, description: 'Decimal string as stored'),
    new OA\Property(property: 'pallet_tare_kg', type: 'string', nullable: true, description: 'Decimal string as stored'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'category', type: 'object', description: 'Item category; present on list, create, show and update'),
    new OA\Property(property: 'quants', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryStockQuant'), description: 'Only on the single item (show)'),
    new OA\Property(property: 'lots', type: 'array', items: new OA\Items(type: 'object'), description: 'Only when loaded'),
    new OA\Property(property: 'reorder_rules', type: 'array', items: new OA\Items(type: 'object'), description: 'Only on the single item (show)'),
    new OA\Property(property: 'total_on_hand', type: 'number'),
    new OA\Property(property: 'total_available', type: 'number'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'InventoryStockItemList', description: 'Paginated stock items', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryStockItem')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryStockQuant', description: 'Inventory balance of an item at a bin (and lot)', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'number', description: 'On hand'),
    new OA\Property(property: 'reserved_quantity', type: 'number'),
    new OA\Property(property: 'available_quantity', type: 'string', description: 'Stored decimal string (on hand less reserved)'),
    new OA\Property(property: 'bin', ref: '#/components/schemas/InventoryWarehouseBin'),
    new OA\Property(property: 'item', ref: '#/components/schemas/InventoryStockItem'),
    new OA\Property(property: 'lot', type: 'object', description: 'Only when loaded'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'InventoryStockQuantList', description: 'Paginated stock quants', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryStockQuant')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryStockMovement', description: 'Entry of the double-entry stock ledger', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'movement_number', type: 'string'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'movement_type', type: 'string'),
    new OA\Property(property: 'quantity', type: 'number', description: 'Unsigned in the ledger'),
    new OA\Property(property: 'signed_quantity', type: 'number', description: 'Negative when the movement takes stock out of a bin without moving it to another one (issue), positive otherwise; transfers show the positive quantity'),
    new OA\Property(property: 'unit_cost', type: 'number'),
    new OA\Property(property: 'total_valuation', type: 'number'),
    new OA\Property(property: 'stock_lot_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'stock_serial_number_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reference_type', type: 'string', nullable: true),
    new OA\Property(property: 'reference_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'item', ref: '#/components/schemas/InventoryStockItem'),
    new OA\Property(property: 'source_bin', ref: '#/components/schemas/InventoryWarehouseBin'),
    new OA\Property(property: 'destination_bin', ref: '#/components/schemas/InventoryWarehouseBin'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'InventoryStockMovementList', description: 'Paginated stock movements', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryStockMovement')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryStockReservation', description: 'Stock reserved for a document', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'source_document_type', type: 'string', nullable: true),
    new OA\Property(property: 'source_document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity_reserved', type: 'number'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'allocated_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'item', ref: '#/components/schemas/InventoryStockItem'),
    new OA\Property(property: 'warehouse', ref: '#/components/schemas/InventoryWarehouse'),
    new OA\Property(property: 'allocations', type: 'array', description: 'Bins (and lots) the quantity is taken from; only when loaded (create)', items: new OA\Items(properties: [
        new OA\Property(property: 'stock_quant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'quantity', type: 'string'),
    ])),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'InventoryStockReservationList', description: 'Paginated stock reservations', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryStockReservation')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryAdjustment', description: 'Stock adjustment with its approval state', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'adjustment_number', type: 'string'),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'net_valuation_change', type: 'number'),
    new OA\Property(property: 'is_locked', type: 'boolean'),
    new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'warehouse', ref: '#/components/schemas/InventoryWarehouse'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'InventoryAdjustmentList', description: 'Paginated stock adjustments', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryAdjustment')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryCountSessionList', description: 'Paginated physical count sessions', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsCountSession')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryPickListPage', description: 'Paginated pick lists', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryPickList')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
#[OA\Schema(schema: 'InventoryLotList', description: 'Paginated lots, earliest expiry first', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLot')),
    new OA\Property(property: 'links', ref: '#/components/schemas/InventoryPaginationLinks'),
    new OA\Property(property: 'meta', ref: '#/components/schemas/InventoryPaginationMeta'),
])]
final class InventoryOpenApiSchemas {}
