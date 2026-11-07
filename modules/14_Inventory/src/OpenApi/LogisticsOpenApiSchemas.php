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
    new OA\Property(property: 'strategy', type: 'string', enum: ['fifo', 'fefo']),
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
class LogisticsOpenApiSchemas {}
