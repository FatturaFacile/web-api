<?php

declare(strict_types=1);

namespace Modules\Returns\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the returns and warranty (RMA) endpoints.
 */
#[OA\Tag(name: 'Returns', description: 'Returns and warranty (RMA): customer returns with inspection and resolution, warranty registry and claims, supplier returns (RTV), settings and summary')]
#[OA\Response(response: 'ReturnsUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ReturnsForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ReturnsNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ReturnsUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'ReturnsRmaLine', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invoice_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'quantity', description: 'Quantity authorised, decimal string', type: 'string', example: '2.0000'),
    new OA\Property(property: 'quantity_received', type: 'string', example: '0.0000'),
    new OA\Property(property: 'quantity_inspected', type: 'string', example: '0.0000'),
    new OA\Property(property: 'unit_price', description: 'Net price of a unit as the customer paid it', type: 'string', example: '49.9000'),
    new OA\Property(property: 'serial_number', type: 'string', nullable: true),
    new OA\Property(property: 'lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'reason_code', type: 'string', nullable: true),
    new OA\Property(property: 'disposition', type: 'string', enum: ['restock', 'scrap', 'refurbish', 'return_to_supplier'], nullable: true),
    new OA\Property(property: 'disposition_warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'inspection_notes', type: 'string', nullable: true),
    new OA\Property(property: 'stock_movement_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'ReturnsRma', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rma_number', type: 'string', example: 'RMA-2027-0001'),
    new OA\Property(property: 'status', type: 'string', enum: ['requested', 'approved', 'received', 'inspected', 'resolved', 'rejected', 'closed']),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string'),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'invoice_number', type: 'string', nullable: true),
    new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reason_code', type: 'string'),
    new OA\Property(property: 'requested_on', type: 'string', format: 'date'),
    new OA\Property(property: 'window_overridden', description: 'Requested after the return window with an approver override', type: 'boolean'),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejected_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'inspected_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'resolution_type', type: 'string', enum: ['refund', 'credit_note', 'replacement', 'repair'], nullable: true),
    new OA\Property(property: 'refund_amount', type: 'string', nullable: true, example: '99.80'),
    new OA\Property(property: 'credit_note_reference', type: 'string', nullable: true),
    new OA\Property(property: 'resolution_notes', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'allowed_transitions', description: 'The statuses the return can move to', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsRmaLine')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'ReturnsSource', type: 'object', properties: [
    new OA\Property(property: 'type', type: 'string', enum: ['invoice', 'sales_order']),
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'number', type: 'string'),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'within_window', description: 'The return window of the settings is still open today', type: 'boolean'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'source_item_id', description: 'Send it as invoice_item_id or sales_order_item_id according to the type', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity_sold', type: 'string', example: '5.0000'),
        new OA\Property(property: 'quantity_returned', type: 'string', example: '1.0000'),
        new OA\Property(property: 'quantity_available', type: 'string', example: '4.0000'),
        new OA\Property(property: 'unit_price', type: 'string', example: '99.9000'),
    ])),
])]
#[OA\Schema(schema: 'ReturnsRmaRequest', type: 'object', required: ['contact_id', 'reason_code', 'lines'], properties: [
    new OA\Property(property: 'contact_id', description: 'The customer (a contact); fixed after creation', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invoice_id', description: 'Source invoice; required when there is no sales_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sales_order_id', description: 'Source sales order; required when there is no invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reason_code', description: 'An active reason code of the settings', type: 'string', maxLength: 40),
    new OA\Property(property: 'requested_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'override_window', description: 'Accept a request after the return window; allowed to approvers only', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', required: ['quantity'], properties: [
        new OA\Property(property: 'invoice_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'quantity', description: 'At most the source quantity less what was already returned', type: 'number', example: 1),
        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'reason_code', type: 'string', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'ReturnsRmaApproveRequest', type: 'object', properties: [
    new OA\Property(property: 'warehouse_id', description: 'Warehouse that will receive the goods', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'ReturnsReasonRequest', type: 'object', required: ['reason'], properties: [
    new OA\Property(property: 'reason', type: 'string', maxLength: 2000),
])]
#[OA\Schema(schema: 'ReturnsRmaReceiveRequest', type: 'object', properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'lines', description: 'Quantities received; a line not listed is received in full', type: 'array', items: new OA\Items(type: 'object', required: ['id', 'quantity_received'], properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity_received', type: 'number', example: 1),
    ])),
])]
#[OA\Schema(schema: 'ReturnsRmaInspectRequest', type: 'object', required: ['lines'], properties: [
    new OA\Property(property: 'lines', description: 'One entry for every received line', type: 'array', items: new OA\Items(type: 'object', required: ['id', 'disposition'], properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity_inspected', description: 'Defaults to the quantity received', type: 'number', nullable: true),
        new OA\Property(property: 'disposition', type: 'string', enum: ['restock', 'scrap', 'refurbish', 'return_to_supplier']),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_id', description: 'Required for return_to_supplier', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'ReturnsRmaResolveRequest', type: 'object', required: ['resolution_type'], properties: [
    new OA\Property(property: 'resolution_type', type: 'string', enum: ['refund', 'credit_note', 'replacement', 'repair']),
    new OA\Property(property: 'refund_amount', description: 'Refund and credit note only; defaults to the value of the inspected goods', type: 'number', nullable: true),
    new OA\Property(property: 'credit_note_reference', description: 'Required for credit_note: the reference of the credit note draft', type: 'string', maxLength: 100, nullable: true),
    new OA\Property(property: 'warehouse_id', description: 'Replacement only: the warehouse the replacement leaves', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ReturnsReasonCode', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'defective'),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'is_defect', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'ReturnsReasonCodeRequest', type: 'object', required: ['code', 'label'], properties: [
    new OA\Property(property: 'code', description: 'Lowercase letters, digits and underscores; unique in the company and fixed after creation', type: 'string', maxLength: 40),
    new OA\Property(property: 'label', type: 'string', maxLength: 120),
    new OA\Property(property: 'is_defect', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'ReturnsSettings', type: 'object', properties: [
    new OA\Property(property: 'return_window_days', type: 'integer', example: 30),
    new OA\Property(property: 'reason_codes', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsReasonCode')),
])]
#[OA\Schema(schema: 'ReturnsSettingsRequest', type: 'object', required: ['return_window_days'], properties: [
    new OA\Property(property: 'return_window_days', type: 'integer', minimum: 0, maximum: 3650),
])]
#[OA\Schema(schema: 'ReturnsWarrantyValidity', type: 'object', properties: [
    new OA\Property(property: 'valid', type: 'boolean'),
    new OA\Property(property: 'reason', type: 'string', enum: ['void', 'not_started', 'expired'], nullable: true),
    new OA\Property(property: 'checked_on', type: 'string', format: 'date'),
    new OA\Property(property: 'days_left', type: 'integer'),
])]
#[OA\Schema(schema: 'ReturnsWarranty', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_name', type: 'string', nullable: true),
    new OA\Property(property: 'product_sku', type: 'string', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'customer_name', type: 'string'),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'serial_number', type: 'string', nullable: true),
    new OA\Property(property: 'lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'coverage_type', type: 'string', enum: ['full', 'parts', 'labor', 'limited']),
    new OA\Property(property: 'coverage_terms', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['active', 'void']),
    new OA\Property(property: 'void_reason', type: 'string', nullable: true),
    new OA\Property(property: 'validity', ref: '#/components/schemas/ReturnsWarrantyValidity'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'ReturnsWarrantyRequest', type: 'object', required: ['product_id', 'contact_id', 'starts_on', 'ends_on'], properties: [
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'serial_number', description: 'Required when there is no lot_number', type: 'string', maxLength: 100, nullable: true),
    new OA\Property(property: 'lot_number', description: 'Required when there is no serial_number', type: 'string', maxLength: 100, nullable: true),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'coverage_type', type: 'string', enum: ['full', 'parts', 'labor', 'limited']),
    new OA\Property(property: 'coverage_terms', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ReturnsWarrantyClaim', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'claim_number', type: 'string', example: 'CLM-2027-0001'),
    new OA\Property(property: 'warranty_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'serial_number', type: 'string', nullable: true),
    new OA\Property(property: 'lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'rma_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rma_number', type: 'string', nullable: true),
    new OA\Property(property: 'claim_date', type: 'string', format: 'date'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'accepted', 'rejected', 'resolved']),
    new OA\Property(property: 'is_valid', description: 'The warranty covered the claim date when the claim was raised', type: 'boolean'),
    new OA\Property(property: 'validity_note', description: 'Why the warranty did not cover the claim (void, not_started, expired)', type: 'string', nullable: true),
    new OA\Property(property: 'decision_notes', type: 'string', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'ReturnsWarrantyClaimRequest', type: 'object', required: ['warranty_id', 'description'], properties: [
    new OA\Property(property: 'warranty_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rma_id', description: 'A return of the same customer', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'claim_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'description', type: 'string', maxLength: 4000),
])]
#[OA\Schema(schema: 'ReturnsClaimAcceptRequest', type: 'object', properties: [
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'override_validity', description: 'Accept a claim the warranty did not cover; allowed to approvers only', type: 'boolean'),
])]
#[OA\Schema(schema: 'ReturnsSupplierReturnLine', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'quantity', type: 'string', example: '3.0000'),
    new OA\Property(property: 'unit_cost', type: 'string', example: '12.5000'),
    new OA\Property(property: 'goods_receipt_item_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rma_line_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'serial_number', type: 'string', nullable: true),
    new OA\Property(property: 'lot_number', type: 'string', nullable: true),
    new OA\Property(property: 'stock_movement_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'ReturnsSupplierReturn', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'return_number', type: 'string', example: 'RTV-2027-0001'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'shipped', 'credited', 'closed', 'cancelled']),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_name', type: 'string', nullable: true),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rma_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reason_code', type: 'string', nullable: true),
    new OA\Property(property: 'carrier', type: 'string', nullable: true),
    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'credit_expected', type: 'string', example: '37.50'),
    new OA\Property(property: 'credit_received', type: 'string', nullable: true),
    new OA\Property(property: 'shipped_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'credited_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'allowed_transitions', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReturnsSupplierReturnLine')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'ReturnsSupplierReturnRequest', type: 'object', required: ['supplier_id', 'lines'], properties: [
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'rma_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reason_code', type: 'string', nullable: true),
    new OA\Property(property: 'carrier', type: 'string', nullable: true),
    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
    new OA\Property(property: 'credit_expected', description: 'Defaults to the value of the lines', type: 'number', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', required: ['product_id', 'quantity'], properties: [
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'quantity', type: 'number', example: 1),
        new OA\Property(property: 'unit_cost', description: 'Defaults to the cost price of the product', type: 'number', nullable: true),
        new OA\Property(property: 'goods_receipt_item_id', description: 'When set, the quantity is limited to what the receipt line brought in less what other returns took back', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'rma_line_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'ReturnsSupplierReturnShipRequest', type: 'object', properties: [
    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'carrier', type: 'string', nullable: true),
    new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ReturnsSupplierReturnCreditRequest', type: 'object', required: ['credit_received'], properties: [
    new OA\Property(property: 'credit_received', type: 'number', example: 37.5),
])]
#[OA\Schema(schema: 'ReturnsSummary', type: 'object', properties: [
    new OA\Property(property: 'period', type: 'object', properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
    ]),
    new OA\Property(property: 'totals', type: 'object', properties: [
        new OA\Property(property: 'rma_count', type: 'integer'),
        new OA\Property(property: 'open_rma_count', type: 'integer'),
        new OA\Property(property: 'refund_value', type: 'string', example: '99.80'),
        new OA\Property(property: 'open_warranty_claims', type: 'integer'),
        new OA\Property(property: 'active_warranties', type: 'integer'),
        new OA\Property(property: 'open_supplier_returns', type: 'integer'),
        new OA\Property(property: 'supplier_credit_expected', type: 'string', example: '37.50'),
    ]),
    new OA\Property(property: 'by_reason', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'reason_code', type: 'string'),
        new OA\Property(property: 'rma_count', type: 'integer'),
        new OA\Property(property: 'quantity', type: 'string'),
    ])),
    new OA\Property(property: 'by_product', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sku', type: 'string', nullable: true),
        new OA\Property(property: 'name', type: 'string', nullable: true),
        new OA\Property(property: 'returned_quantity', type: 'string'),
        new OA\Property(property: 'sold_quantity', type: 'string'),
        new OA\Property(property: 'return_rate', description: 'Returned over invoiced quantity in percent; null when nothing was invoiced in the period', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'ageing', description: 'Open returns by days since the request', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'bucket', type: 'string', enum: ['0_7', '8_30', '31_60', 'over_60']),
        new OA\Property(property: 'count', type: 'integer'),
    ])),
    new OA\Property(property: 'by_status', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
])]
final class ReturnsOpenApi {}
