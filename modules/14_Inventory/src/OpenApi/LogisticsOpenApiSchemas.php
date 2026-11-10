<?php

declare(strict_types=1);

namespace Modules\Inventory\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'InventoryLocation', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'barcode', type: 'string'),
    new OA\Property(property: 'pick_sequence', type: 'integer', nullable: true),
])]
#[OA\Schema(schema: 'InventoryLotRequest', required: ['stock_item_id', 'lot_number'], properties: [
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_number', type: 'string', example: 'L2026-114'),
    new OA\Property(property: 'lot_level', type: 'string', enum: ['raw_material', 'packaged']),
    new OA\Property(property: 'supplier_lot_number', type: 'string'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'manufacturing_date', type: 'string', format: 'date'),
    new OA\Property(property: 'expiration_date', type: 'string', format: 'date'),
    new OA\Property(property: 'received_at', type: 'string', format: 'date'),
    new OA\Property(property: 'certification_scheme', type: 'string', description: 'Free text: the scheme named on the certificate'),
    new OA\Property(property: 'certification_reference', type: 'string'),
    new OA\Property(property: 'certification_date', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'InventoryLot', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_number', type: 'string'),
    new OA\Property(property: 'lot_level', type: 'string', enum: ['raw_material', 'packaged']),
    new OA\Property(property: 'supplier_lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'manufacturing_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_quarantined', type: 'boolean'),
    new OA\Property(property: 'certification_scheme', type: 'string', nullable: true),
    new OA\Property(property: 'certification_reference', type: 'string', nullable: true),
    new OA\Property(property: 'certification_date', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'InventoryLotStock', properties: [
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_number', type: 'string'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'certification_scheme', type: 'string', nullable: true),
    new OA\Property(property: 'certification_reference', type: 'string', nullable: true),
    new OA\Property(property: 'certification_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'quantity_on_hand', type: 'string', example: '120.0000'),
    new OA\Property(property: 'quantity_reserved', type: 'string', example: '10.0000'),
])]
#[OA\Schema(schema: 'InventoryLotTrace', description: 'A lot and, nested in `lots`, the lots it comes from or that derive from it', properties: [
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_number', type: 'string'),
    new OA\Property(property: 'lot_level', type: 'string'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sku', type: 'string'),
    new OA\Property(property: 'item_name', type: 'string'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'supplier_lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'manufacturing_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'quantity_consumed', type: 'string', nullable: true),
    new OA\Property(property: 'production_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'deliveries', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ddt_number', type: 'string'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'quantity', type: 'string'),
    ])),
    new OA\Property(property: 'lots', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLotTrace')),
])]
#[OA\Schema(schema: 'InventoryAvailability', properties: [
    new OA\Property(property: 'strategy', type: 'string', enum: ['fifo', 'fefo', 'lifo']),
    new OA\Property(property: 'quants', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'quant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'quantity_available', type: 'string', example: '10.0000'),
    ])),
])]
#[OA\Schema(schema: 'InventoryStockReservationDetail', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'source_document_type', type: 'string'),
    new OA\Property(property: 'source_document_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'quantity_reserved', type: 'number'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'allocations', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'stock_quant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'quantity', type: 'string'),
    ])),
])]
#[OA\Schema(schema: 'InventoryBalanceAsOf', properties: [
    new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'string', example: '10.0000'),
])]
#[OA\Schema(schema: 'InventoryPickLine', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity_to_pick', type: 'string'),
    new OA\Property(property: 'quantity_picked', type: 'string'),
    new OA\Property(property: 'sequence_number', type: 'integer'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'picked', 'shorted', 'cancelled']),
])]
#[OA\Schema(schema: 'InventoryPickList', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'pick_list_number', type: 'string'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'assigned_picker_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'assigned', 'picking', 'completed', 'cancelled']),
    new OA\Property(property: 'origin_document_type', type: 'string', nullable: true),
    new OA\Property(property: 'origin_document_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'warehouse', ref: '#/components/schemas/InventoryWarehouse', description: 'Only when loaded (show)'),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'pick_wave_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'shipped_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'shortages', type: 'array', nullable: true, items: new OA\Items(properties: [
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'requested', type: 'string'),
        new OA\Property(property: 'short', type: 'string'),
    ])),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryPickLine'), description: 'Only when loaded (create, show; not in the list)'),
])]
#[OA\Schema(schema: 'InventoryPickStop', properties: [
    new OA\Property(property: 'remaining_stops', type: 'integer'),
    new OA\Property(property: 'line', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sequence_number', type: 'integer'),
        new OA\Property(property: 'bin_code', type: 'string'),
        new OA\Property(property: 'bin_barcode', type: 'string'),
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sku', type: 'string'),
        new OA\Property(property: 'item_name', type: 'string'),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'quantity_to_pick', type: 'string'),
    ], type: 'object'),
])]
#[OA\Schema(schema: 'InventoryPackagingPlan', properties: [
    new OA\Property(property: 'cartons', type: 'integer'),
    new OA\Property(property: 'pallets', type: 'integer'),
    new OA\Property(property: 'net_weight_kg', type: 'string'),
    new OA\Property(property: 'gross_weight_kg', type: 'string'),
    new OA\Property(property: 'volume_m3', type: 'string'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity', type: 'string'),
        new OA\Property(property: 'units_per_carton', type: 'integer', nullable: true),
        new OA\Property(property: 'cartons', type: 'integer'),
        new OA\Property(property: 'pallets', type: 'integer'),
        new OA\Property(property: 'net_weight_kg', type: 'string'),
        new OA\Property(property: 'tare_weight_kg', type: 'string'),
        new OA\Property(property: 'gross_weight_kg', type: 'string'),
        new OA\Property(property: 'volume_m3', type: 'string'),
    ])),
])]
#[OA\Schema(schema: 'InventoryPackingList', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'packing_number', type: 'string'),
    new OA\Property(property: 'stock_pick_list_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'total_packages', type: 'integer'),
    new OA\Property(property: 'cartons_count', type: 'integer'),
    new OA\Property(property: 'pallets_count', type: 'integer'),
    new OA\Property(property: 'net_weight_kg', type: 'string'),
    new OA\Property(property: 'gross_weight_kg', type: 'string'),
    new OA\Property(property: 'total_volume_m3', type: 'string'),
    new OA\Property(property: 'packages', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'package_number', type: 'string'),
        new OA\Property(property: 'package_type', type: 'string', enum: ['box', 'carton', 'pallet']),
        new OA\Property(property: 'parent_package_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'net_weight_kg', type: 'string'),
        new OA\Property(property: 'tare_weight_kg', type: 'string'),
        new OA\Property(property: 'gross_weight_kg', type: 'string'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
    ])),
])]
#[OA\Schema(schema: 'InventoryTerminalResult', description: 'The result of the operation; `replayed` is true when this scan had already been applied', properties: [
    new OA\Property(property: 'replayed', type: 'boolean'),
    new OA\Property(property: 'movement_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'pick_line_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'count_line_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string'),
])]
#[OA\Schema(schema: 'InventoryBarcodeLabel', properties: [
    new OA\Property(property: 'target', type: 'string'),
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'symbology', type: 'string', enum: ['ean13', 'code39']),
    new OA\Property(property: 'value', type: 'string'),
    new OA\Property(property: 'format', type: 'string', enum: ['svg', 'png']),
    new OA\Property(property: 'content', type: 'string', description: 'SVG markup, or the PNG as base64'),
])]
#[OA\Schema(schema: 'InventoryCarrier', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'driver', type: 'string', enum: ['local', 'dpd_ch']),
    new OA\Property(property: 'contract_number', type: 'string', nullable: true),
    new OA\Property(property: 'tracking_url_template', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'InventoryShipmentRecipient', required: ['name', 'address', 'city', 'postal_code', 'country'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'address', type: 'string'),
    new OA\Property(property: 'city', type: 'string'),
    new OA\Property(property: 'postal_code', type: 'string'),
    new OA\Property(property: 'country', type: 'string', example: 'CH'),
    new OA\Property(property: 'phone', type: 'string'),
    new OA\Property(property: 'email', type: 'string', format: 'email'),
])]
#[OA\Schema(schema: 'InventoryShipment', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'shipment_number', type: 'string'),
    new OA\Property(property: 'shipping_carrier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_packing_list_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned']),
    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
    new OA\Property(property: 'tracking_url', type: 'string', nullable: true),
    new OA\Property(property: 'recipient', ref: '#/components/schemas/InventoryShipmentRecipient'),
    new OA\Property(property: 'packages_count', type: 'integer'),
    new OA\Property(property: 'gross_weight_kg', type: 'string', nullable: true),
    new OA\Property(property: 'label_format', type: 'string', nullable: true),
    new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'tracking_events', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'location', type: 'string', nullable: true),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'source', type: 'string', enum: ['system', 'manual', 'carrier']),
    ])),
])]
#[OA\Schema(schema: 'ProductChargeRate', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_charge_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rate', type: 'string', example: '0.125000'),
    new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'ProductChargeType', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'calculation_basis', type: 'string', enum: ['per_unit', 'per_kg', 'per_litre', 'percent_of_net']),
    new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'rates', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductChargeRate')),
])]
#[OA\Schema(schema: 'ProductChargeAssignment', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_charge_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'quantity_per_unit', type: 'string'),
])]
#[OA\Schema(schema: 'ProductChargeLine', properties: [
    new OA\Property(property: 'product_charge_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'quantity', type: 'number'),
    new OA\Property(property: 'unit_price', type: 'number'),
    new OA\Property(property: 'tax_rate', type: 'number'),
    new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'notes', type: 'string'),
])]
#[OA\Schema(schema: 'SafetyDataSheetVersion', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'safety_data_sheet_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version_number', type: 'integer'),
    new OA\Property(property: 'revision_date', type: 'string', format: 'date'),
    new OA\Property(property: 'hazard_classes', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'signal_word', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'original_name', type: 'string'),
    new OA\Property(property: 'mime_type', type: 'string'),
    new OA\Property(property: 'size', type: 'integer'),
])]
#[OA\Schema(schema: 'SafetyDataSheet', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'language', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'current_version_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'current_version', ref: '#/components/schemas/SafetyDataSheetVersion', nullable: true),
])]
#[OA\Schema(schema: 'SafetyDataSheetDelivery', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'safety_data_sheet_version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'email', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['sent', 'skipped', 'failed']),
    new OA\Property(property: 'error', type: 'string', nullable: true),
    new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'WeighbridgeVehicle', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'license_plate', type: 'string'),
    new OA\Property(property: 'card_number', type: 'string', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'stored_tare_kg', type: 'string', nullable: true),
    new OA\Property(property: 'tare_recorded_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'WeighbridgeReading', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'device_code', type: 'string'),
    new OA\Property(property: 'weight_kg', type: 'string'),
    new OA\Property(property: 'is_stable', type: 'boolean'),
    new OA\Property(property: 'read_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'WeighbridgeTicket', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ticket_number', type: 'string'),
    new OA\Property(property: 'weighbridge_vehicle_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound']),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'closed', 'billed']),
    new OA\Property(property: 'first_weight_kg', type: 'string'),
    new OA\Property(property: 'second_weight_kg', type: 'string', nullable: true),
    new OA\Property(property: 'used_stored_tare', type: 'boolean'),
    new OA\Property(property: 'gross_kg', type: 'string', nullable: true),
    new OA\Property(property: 'tare_kg', type: 'string', nullable: true),
    new OA\Property(property: 'net_kg', type: 'string', nullable: true),
    new OA\Property(property: 'delivery_note_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'billing_uom', type: 'string', enum: ['kg', 't', 'm3'], nullable: true),
    new OA\Property(property: 'density_kg_m3', type: 'string', nullable: true),
    new OA\Property(property: 'billed_quantity', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PortalStockLine', properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sku', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'expiration_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'quantity_on_hand', type: 'string'),
    new OA\Property(property: 'quantity_reserved', type: 'string'),
    new OA\Property(property: 'quantity_available', type: 'string'),
])]
#[OA\Schema(schema: 'PortalStockMovement', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'executed_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out', 'internal']),
    new OA\Property(property: 'sku', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'quantity', type: 'string'),
])]
#[OA\Schema(schema: 'PortalMovementRequest', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'request_type', type: 'string', enum: ['withdrawal', 'return', 'transfer']),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'requested_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected']),
    new OA\Property(property: 'decision_note', type: 'string', nullable: true),
    new OA\Property(property: 'stock_reservation_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'SerialWarranty', properties: [
    new OA\Property(property: 'stock_serial_number_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'serial_number', type: 'string'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'item_name', type: 'string'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'purchase_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'purchase_value', type: 'string', nullable: true),
    new OA\Property(property: 'purchase_currency', type: 'string', nullable: true),
    new OA\Property(property: 'warranty_start_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'warranty_end_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'warranty_terms', type: 'string', nullable: true),
    new OA\Property(property: 'warranty', properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['none', 'not_started', 'active', 'expired']),
        new OA\Property(property: 'days_left', type: 'integer', nullable: true),
    ], type: 'object'),
    new OA\Property(property: 'open_repairs', type: 'integer'),
])]
#[OA\Schema(schema: 'RepairOrderLine', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'lot_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'unit_cost', type: 'string'),
    new OA\Property(property: 'line_total', type: 'string'),
])]
#[OA\Schema(schema: 'RepairOrder', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'repair_number', type: 'string'),
    new OA\Property(property: 'stock_serial_number_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'purchase_value', type: 'string', nullable: true),
    new OA\Property(property: 'purchase_currency', type: 'string', nullable: true),
    new OA\Property(property: 'is_under_warranty', type: 'boolean'),
    new OA\Property(property: 'status', type: 'string', enum: ['received', 'diagnosing', 'waiting_parts', 'repairing', 'ready', 'delivered', 'cancelled']),
    new OA\Property(property: 'fault_description', type: 'string'),
    new OA\Property(property: 'diagnosis', type: 'string', nullable: true),
    new OA\Property(property: 'resolution', type: 'string', nullable: true),
    new OA\Property(property: 'labour_amount', type: 'string'),
    new OA\Property(property: 'parts_amount', type: 'string'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/RepairOrderLine')),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'invoiced_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'RepairOrderInvoiceRequest', required: ['tax_rate'], properties: [
    new OA\Property(property: 'tax_rate', description: 'VAT rate of the labour and of the parts that state none.', type: 'number'),
    new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'currency', type: 'string', maxLength: 3, minLength: 3, nullable: true),
    new OA\Property(property: 'parts', type: 'array', nullable: true, items: new OA\Items(required: ['line_id'], properties: [
        new OA\Property(property: 'line_id', description: 'Part line of the repair.', type: 'string', format: 'uuid'),
        new OA\Property(property: 'unit_price', description: 'Sale price; the sale price of the product when omitted.', type: 'number', nullable: true),
        new OA\Property(property: 'tax_rate', type: 'number', nullable: true),
    ], type: 'object')),
])]
#[OA\Schema(schema: 'RepairOrderInvoiceResponse', properties: [
    new OA\Property(property: 'repair_order', ref: '#/components/schemas/RepairOrder'),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invoice_number', type: 'string'),
    new OA\Property(property: 'total_gross', type: 'string'),
])]
class LogisticsOpenApiSchemas {}
