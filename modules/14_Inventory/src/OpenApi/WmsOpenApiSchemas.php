<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response and request payloads of the warehouse management (WMS) endpoints of the inventory module. Quantities are
 * decimal strings with 4 decimals, values decimal strings with 2.
 */
#[OA\Schema(schema: 'WmsOccupancy', description: 'What a bin holds now, from the stock quants', properties: [
    new OA\Property(property: 'quantity', type: 'string', example: '48.0000'),
    new OA\Property(property: 'weight_kg', type: 'string', example: '96.000'),
    new OA\Property(property: 'volume_m3', type: 'string', example: '0.48000'),
    new OA\Property(property: 'items', type: 'integer', example: 2),
])]
#[OA\Schema(schema: 'WmsZone', description: 'A warehouse zone', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'Z1'),
    new OA\Property(property: 'name', type: 'string', example: 'Scaffalature'),
    new OA\Property(property: 'zone_type', type: 'string', enum: ['receiving', 'storage', 'picking', 'packing', 'shipping', 'quarantine', 'transit']),
    new OA\Property(property: 'bins_count', type: 'integer', example: 24),
])]
#[OA\Schema(schema: 'WmsBin', description: 'A bin with type, capacities and occupancy', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'zone_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'zone_code', type: 'string', example: 'Z1'),
    new OA\Property(property: 'zone_type', type: 'string', example: 'storage'),
    new OA\Property(property: 'aisle_code', type: 'string', example: 'A1'),
    new OA\Property(property: 'rack_code', type: 'string', example: 'R1'),
    new OA\Property(property: 'code', type: 'string', example: 'B01'),
    new OA\Property(property: 'barcode', type: 'string', example: 'BIN-A1-R1-B01'),
    new OA\Property(property: 'bin_type', type: 'string', enum: ['storage', 'picking', 'bulk', 'receiving', 'staging', 'shipping', 'quarantine', 'transit']),
    new OA\Property(property: 'pick_sequence', type: 'integer', nullable: true),
    new OA\Property(property: 'is_locked', type: 'boolean'),
    new OA\Property(property: 'max_quantity', type: 'string', nullable: true, example: '200.0000'),
    new OA\Property(property: 'max_weight_kg', type: 'string', nullable: true, example: '500.00'),
    new OA\Property(property: 'max_volume_cbm', type: 'string', nullable: true, example: '1.2000'),
    new OA\Property(property: 'occupancy', ref: '#/components/schemas/WmsOccupancy'),
])]
#[OA\Schema(schema: 'WmsPutawayRuleInput', description: 'Fields of a putaway rule (create: warehouse_id, strategy and one target required)', properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', nullable: true, description: 'Rule for one item'),
    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true, description: 'Rule for an item category (when no item)'),
    new OA\Property(property: 'target_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'target_zone_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'bin_type', type: 'string', enum: ['storage', 'picking', 'bulk'], nullable: true),
    new OA\Property(property: 'strategy', type: 'string', enum: ['fixed_location', 'consolidate', 'empty_bin']),
    new OA\Property(property: 'priority', type: 'integer', example: 10),
    new OA\Property(property: 'is_active', type: 'boolean', example: true),
])]
#[OA\Schema(schema: 'WmsPutawayRule', description: 'A putaway rule', allOf: [
    new OA\Schema(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
    new OA\Schema(ref: '#/components/schemas/WmsPutawayRuleInput'),
])]
#[OA\Schema(schema: 'WmsPutawaySuggestion', description: 'A bin suggested for putaway', properties: [
    new OA\Property(property: 'bin_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'barcode', type: 'string'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'zone_code', type: 'string', nullable: true),
    new OA\Property(property: 'bin_type', type: 'string'),
    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'strategy', type: 'string', example: 'consolidate'),
    new OA\Property(property: 'source', type: 'string', enum: ['rule', 'fallback']),
    new OA\Property(property: 'occupancy', ref: '#/components/schemas/WmsOccupancy'),
])]
#[OA\Schema(schema: 'WmsMovement', description: 'A double-entry stock movement', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'source_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'destination_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'stock_lot_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'number', example: 24),
    new OA\Property(property: 'unit_cost', type: 'number', example: 2.5),
    new OA\Property(property: 'total_valuation', type: 'number', example: 60),
    new OA\Property(property: 'movement_type', type: 'string', example: 'putaway'),
])]
#[OA\Schema(schema: 'WmsSalesReservationLine', description: 'Reservation result of a sales order line', properties: [
    new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'open', type: 'string', example: '10.0000'),
    new OA\Property(property: 'already_reserved', type: 'string', example: '0.0000'),
    new OA\Property(property: 'reserved', type: 'string', example: '8.0000'),
    new OA\Property(property: 'short', type: 'string', example: '2.0000'),
    new OA\Property(property: 'reservation_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'WmsPickWave', description: 'A picking wave with its pick lists', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'wave_number', type: 'string', example: 'WAVE-0001'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string', enum: ['released', 'completed', 'cancelled']),
    new OA\Property(property: 'staging_bin_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'assigned_picker_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'shortages', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'short', type: 'string', example: '2.0000'),
    ])),
    new OA\Property(property: 'released_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'pick_lists', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'WmsShipmentConfirmation', description: 'The delivery note created by a shipment confirmation', properties: [
    new OA\Property(property: 'pick_list_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string', example: 'shipped'),
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ddt_number', type: 'string', example: 'DDT-0007'),
    new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'string', example: '4.0000'),
    ])),
])]
#[OA\Schema(schema: 'WmsTransferOrder', description: 'A transfer order between warehouses', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'transfer_number', type: 'string', example: 'TO-0003'),
    new OA\Property(property: 'source_warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'destination_warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'transit_bin_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'in_transit', 'partially_received', 'received', 'cancelled']),
    new OA\Property(property: 'requested_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'shipped_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'quantity', type: 'string', example: '12.0000'),
        new OA\Property(property: 'quantity_shipped', type: 'string', example: '12.0000'),
        new OA\Property(property: 'quantity_received', type: 'string', example: '5.0000'),
        new OA\Property(property: 'quantity_in_transit', type: 'string', example: '7.0000'),
        new OA\Property(property: 'shipped_lots', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'quantity_shipped', type: 'string'),
            new OA\Property(property: 'quantity_received', type: 'string'),
        ])),
    ])),
])]
#[OA\Schema(schema: 'WmsAbcRow', description: 'ABC class of an item', properties: [
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sku', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'consumption_value', type: 'string', example: '12500.00'),
    new OA\Property(property: 'cumulative_share', type: 'string', example: '64.20'),
    new OA\Property(property: 'abc_class', type: 'string', enum: ['A', 'B', 'C']),
])]
#[OA\Schema(schema: 'WmsCycleCountPlanInput', description: 'Fields of a cycle count plan (create: warehouse_id, name, interval_days required)', properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string', example: 'Classe A mensile'),
    new OA\Property(property: 'abc_class', type: 'string', enum: ['A', 'B', 'C'], nullable: true),
    new OA\Property(property: 'interval_days', type: 'integer', example: 30),
    new OA\Property(property: 'freezes_stock', type: 'boolean', example: true),
    new OA\Property(property: 'is_active', type: 'boolean', example: true),
    new OA\Property(property: 'next_run_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'WmsCycleCountPlan', description: 'A cycle count plan', allOf: [
    new OA\Schema(properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'last_run_at', type: 'string', format: 'date-time', nullable: true),
    ]),
    new OA\Schema(ref: '#/components/schemas/WmsCycleCountPlanInput'),
])]
#[OA\Schema(schema: 'WmsCountSession', description: 'A physical count session', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'session_number', type: 'string', example: 'CNT-0004'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_zone_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'count_type', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['in_progress', 'pending_approval', 'reconciled', 'cancelled']),
    new OA\Property(property: 'scheduled_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'effective_at', type: 'string', format: 'date-time', nullable: true, description: 'Moment the system quantities are taken at'),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'is_locked', type: 'boolean'),
    new OA\Property(property: 'freezes_stock', type: 'boolean'),
    new OA\Property(property: 'frozen_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'cycle_count_plan_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'inventory_adjustment_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'warehouse', ref: '#/components/schemas/InventoryWarehouse', description: 'Only when loaded (create, show)'),
    new OA\Property(property: 'lines', type: 'array', description: 'Count lines as stored (system and counted quantities per bin and item); only when loaded (create, show)', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'WmsReorderRuleInput', description: 'Fields of a reorder rule (create: all levels required; minimum <= reorder point <= maximum)', properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', description: 'Only on create'),
    new OA\Property(property: 'min_stock_level', type: 'number', example: 10),
    new OA\Property(property: 'reorder_point', type: 'number', example: 15),
    new OA\Property(property: 'max_stock_level', type: 'number', example: 50),
    new OA\Property(property: 'economic_order_quantity', type: 'number', example: 30),
    new OA\Property(property: 'lead_time_days', type: 'integer', example: 7),
    new OA\Property(property: 'preferred_supplier_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'auto_trigger_requisition', type: 'boolean'),
])]
#[OA\Schema(schema: 'WmsReorderRule', description: 'A reorder rule', allOf: [
    new OA\Schema(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
    new OA\Schema(ref: '#/components/schemas/WmsReorderRuleInput'),
])]
#[OA\Schema(schema: 'WmsReplenishmentSuggestion', description: 'Replenishment situation of a reorder rule', properties: [
    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sku', type: 'string'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'available', type: 'string', example: '8.0000'),
    new OA\Property(property: 'min_stock_level', type: 'string'),
    new OA\Property(property: 'reorder_point', type: 'string'),
    new OA\Property(property: 'max_stock_level', type: 'string'),
    new OA\Property(property: 'economic_order_quantity', type: 'string'),
    new OA\Property(property: 'needs_replenishment', type: 'boolean'),
    new OA\Property(property: 'suggested_quantity', type: 'string', example: '42.0000'),
    new OA\Property(property: 'preferred_supplier_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'lead_time_days', type: 'integer'),
])]
#[OA\Schema(schema: 'WmsValuationReport', description: 'Stock valuation at a moment', properties: [
    new OA\Property(property: 'as_of', type: 'string', format: 'date-time'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'total_value', type: 'string', example: '15230.50'),
    new OA\Property(property: 'warehouses', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_code', type: 'string'),
        new OA\Property(property: 'total_value', type: 'string'),
    ])),
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'string', example: '10.0000'),
        new OA\Property(property: 'unit_cost', type: 'string', example: '12.5000'),
        new OA\Property(property: 'value', type: 'string', example: '125.00'),
        new OA\Property(property: 'valuation_method', type: 'string', enum: ['fifo', 'moving_average', 'standard_cost']),
        new OA\Property(property: 'cost_source', type: 'string', enum: ['movements', 'current_moving_average', 'fifo_layers', 'fifo_layers_and_moving_average', 'standard_cost']),
    ])),
])]
#[OA\Schema(schema: 'WmsKpis', description: 'Warehouse KPIs of a period', properties: [
    new OA\Property(property: 'from', type: 'string', format: 'date'),
    new OA\Property(property: 'to', type: 'string', format: 'date'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'period_days', type: 'integer'),
    new OA\Property(property: 'stock_value_start', type: 'string'),
    new OA\Property(property: 'stock_value_end', type: 'string'),
    new OA\Property(property: 'average_stock_value', type: 'string'),
    new OA\Property(property: 'consumption_value', type: 'string'),
    new OA\Property(property: 'inventory_turnover', type: 'string', nullable: true, example: '4.2000'),
    new OA\Property(property: 'coverage_days', type: 'string', nullable: true, example: '86.90'),
    new OA\Property(property: 'dead_stock', type: 'object', properties: [
        new OA\Property(property: 'days', type: 'integer'),
        new OA\Property(property: 'items_count', type: 'integer'),
        new OA\Property(property: 'value', type: 'string'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'sku', type: 'string'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'quantity', type: 'string'),
            new OA\Property(property: 'value', type: 'string'),
            new OA\Property(property: 'last_consumed_at', type: 'string', nullable: true),
        ])),
    ]),
    new OA\Property(property: 'accuracy', type: 'object', properties: [
        new OA\Property(property: 'counted_lines', type: 'integer'),
        new OA\Property(property: 'exact_lines', type: 'integer'),
        new OA\Property(property: 'accuracy_percent', type: 'string', nullable: true, example: '96.50'),
        new OA\Property(property: 'absolute_difference_value', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'WmsBarcodeLookup', description: 'Records matched by a scanned code', properties: [
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'gs1', type: 'object', description: 'Decoded GS1 elements (only those present in the code)', properties: [
        new OA\Property(property: 'gtin', type: 'string', example: '04006381333931'),
        new OA\Property(property: 'lot', type: 'string', example: 'L-2026-01'),
        new OA\Property(property: 'expiry', type: 'string', example: '271200', description: 'AI 17 as scanned, YYMMDD'),
        new OA\Property(property: 'expiry_date', type: 'string', format: 'date', example: '2027-12-31'),
        new OA\Property(property: 'serial', type: 'string', example: 'SN-1'),
    ]),
    new OA\Property(property: 'matches', type: 'array', description: 'One entry per matched record; the fields present depend on type (item: stock_item_id, product_id, sku, name, uom, tracking_type; bin: warehouse_bin_id, warehouse_id, code, barcode, bin_type, zone_type, is_locked; lot: lot_id, lot_number, supplier_lot_number, stock_item_id, sku, expiration_date, is_quarantined; serial_number: serial_number_id, serial_number, stock_item_id, sku, status, warehouse_bin_id, lot_id). Serial number matches carry no stock list.', items: new OA\Items(properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['item', 'bin', 'lot', 'serial_number']),
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', description: 'item, lot, serial_number'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true, description: 'item'),
        new OA\Property(property: 'sku', type: 'string', nullable: true, description: 'item, lot, serial_number'),
        new OA\Property(property: 'name', type: 'string', description: 'item'),
        new OA\Property(property: 'uom', type: 'string', description: 'item'),
        new OA\Property(property: 'tracking_type', type: 'string', description: 'item'),
        new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid', nullable: true, description: 'bin (the bin), serial_number (current bin)'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true, description: 'bin'),
        new OA\Property(property: 'code', type: 'string', description: 'bin'),
        new OA\Property(property: 'barcode', type: 'string', nullable: true, description: 'bin'),
        new OA\Property(property: 'bin_type', type: 'string', description: 'bin'),
        new OA\Property(property: 'zone_type', type: 'string', nullable: true, description: 'bin'),
        new OA\Property(property: 'is_locked', type: 'boolean', description: 'bin'),
        new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true, description: 'lot, serial_number'),
        new OA\Property(property: 'lot_number', type: 'string', description: 'lot'),
        new OA\Property(property: 'supplier_lot_number', type: 'string', nullable: true, description: 'lot'),
        new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true, description: 'lot'),
        new OA\Property(property: 'is_quarantined', type: 'boolean', description: 'lot'),
        new OA\Property(property: 'serial_number_id', type: 'string', format: 'uuid', description: 'serial_number'),
        new OA\Property(property: 'serial_number', type: 'string', description: 'serial_number'),
        new OA\Property(property: 'status', type: 'string', description: 'serial_number'),
        new OA\Property(property: 'stock', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'bin_barcode', type: 'string', nullable: true),
            new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'on_hand', type: 'string'),
            new OA\Property(property: 'reserved', type: 'string'),
            new OA\Property(property: 'available', type: 'string'),
        ])),
    ])),
])]
#[OA\Schema(schema: 'WmsLotRecall', description: 'Recall report of a lot', properties: [
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_number', type: 'string'),
    new OA\Property(property: 'quarantined', type: 'boolean'),
    new OA\Property(property: 'affected_lots', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'lot_number', type: 'string'),
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sku', type: 'string', nullable: true),
        new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_quarantined', type: 'boolean'),
        new OA\Property(property: 'on_hand', type: 'string'),
        new OA\Property(property: 'stock', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'deliveries', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'ddt_number', type: 'string'),
            new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'quantity', type: 'string'),
        ])),
        new OA\Property(property: 'issues', type: 'array', items: new OA\Items(type: 'object')),
    ])),
    new OA\Property(property: 'customers', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'delivery_notes', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    ])),
    new OA\Property(property: 'totals', type: 'object', properties: [
        new OA\Property(property: 'lots', type: 'integer'),
        new OA\Property(property: 'on_hand', type: 'string'),
        new OA\Property(property: 'delivered', type: 'string'),
    ]),
])]
final class WmsOpenApiSchemas {}
