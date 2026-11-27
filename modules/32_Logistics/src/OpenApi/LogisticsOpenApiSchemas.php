<?php

declare(strict_types=1);

namespace Modules\Logistics\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeliveryNoteInput',
    required: ['type', 'items'],
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['outbound_sale', 'internal_transfer', 'subcontracting_out', 'subcontracting_return', 'subcontracting_finished_return', 'customer_consignment', 'customer_consignment_return', 'vendor_consignment_return', 'return_from_customer']),
        new OA\Property(property: 'transport_reason_code', type: 'string', enum: ['sale', 'internal_transfer', 'subcontracting', 'consignment', 'consignment_delivery', 'consignment_return', 'subcontractor_return', 'on_approval', 'repair', 'gift', 'return']),
        new OA\Property(property: 'carriage_terms', type: 'string', enum: ['free', 'collect', 'free_with_charge']),
        new OA\Property(property: 'transport_by', type: 'string', enum: ['sender', 'recipient', 'carrier']),
        new OA\Property(property: 'incoterms', type: 'string', example: 'DAP'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'origin_warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'destination_warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'packages_count', type: 'integer'),
        new OA\Property(property: 'gross_weight_kg', type: 'number'),
        new OA\Property(property: 'net_weight_kg', type: 'number'),
        new OA\Property(property: 'volume_m3', type: 'number'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'quantity', type: 'number'),
            new OA\Property(property: 'unit_price', type: 'number'),
            new OA\Property(property: 'tax_rate', type: 'number'),
        ], type: 'object')),
    ],
)]
#[OA\Schema(
    schema: 'DeliveryNoteFromOrdersInput',
    required: ['orders'],
    properties: [
        new OA\Property(property: 'orders', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'items', type: 'array', description: 'Omit to ship the remaining quantity', items: new OA\Items(properties: [
                new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'number'),
            ], type: 'object')),
        ], type: 'object')),
    ],
)]
#[OA\Schema(
    schema: 'DeferredInvoiceInput',
    required: ['delivery_note_ids'],
    properties: [
        new OA\Property(property: 'delivery_note_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'enforce_same_month', type: 'boolean', default: true),
    ],
)]
#[OA\Schema(
    schema: 'TransferInput',
    required: ['purpose', 'origin_warehouse_id', 'destination_warehouse_id', 'lines'],
    properties: [
        new OA\Property(property: 'purpose', type: 'string', enum: ['inter_warehouse', 'technician_van', 'inter_company', 'customer_consignment', 'consignment_return', 'subcontracting', 'subcontracting_return']),
        new OA\Property(property: 'origin_warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'destination_warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'transit_warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'quantity', type: 'number'),
        ], type: 'object')),
    ],
)]
#[OA\Schema(
    schema: 'DiscrepancyResolutionInput',
    required: ['resolution'],
    properties: [new OA\Property(property: 'resolution', type: 'string', enum: ['approved_write_off', 'carrier_claim', 'returned_to_origin'])],
)]
#[OA\Schema(
    schema: 'ConsumptionInput',
    required: ['warehouse_id', 'period_start', 'period_end', 'lines'],
    properties: [
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'quantity', type: 'number'),
            new OA\Property(property: 'unit_price', type: 'number', nullable: true),
        ], type: 'object')),
    ],
)]
#[OA\Schema(
    schema: 'TransferReceiptInput',
    properties: [
        new OA\Property(property: 'final', type: 'boolean', default: true, description: 'false = partial receipt, the rest is still in transit'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'received_qty', type: 'number'),
            new OA\Property(property: 'damaged_qty', type: 'number'),
            new OA\Property(property: 'discrepancy_reason', type: 'string', nullable: true),
        ], type: 'object')),
    ],
)]
final class LogisticsOpenApiSchemas {}
