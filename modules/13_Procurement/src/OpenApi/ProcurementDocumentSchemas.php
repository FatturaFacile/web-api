<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response schemas of the purchasing documents of the procurement API, written from the API resources (the fields that
 * are returned, with their real types: amounts of orders and receipts are decimal strings, those of requisitions,
 * contracts and match logs are numbers). Lists are Laravel paginators serialised with `data`, `links` and `meta`.
 */
#[OA\Schema(
    schema: 'ProcurementPaginationLinks',
    description: 'Navigation links of a Laravel paginator',
    properties: [
        new OA\Property(property: 'first', type: 'string', nullable: true),
        new OA\Property(property: 'last', type: 'string', nullable: true),
        new OA\Property(property: 'prev', type: 'string', nullable: true),
        new OA\Property(property: 'next', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementPaginationMetaFull',
    description: 'Meta of a Laravel paginator as returned by the resource collections',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 6),
        new OA\Property(property: 'links', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'url', type: 'string', nullable: true),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'active', type: 'boolean'),
        ], type: 'object')),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'per_page', type: 'integer', example: 25),
        new OA\Property(property: 'to', type: 'integer', nullable: true, example: 25),
        new OA\Property(property: 'total', type: 'integer', example: 150),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementPurchaseOrderLine',
    description: 'Line of a purchase order; quantities and amounts are decimal strings',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'line_number', type: 'integer'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'supplier_catalog_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_contract_line_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'quantity_ordered', type: 'string', example: '10.0000'),
        new OA\Property(property: 'quantity_received', type: 'string', example: '0.0000'),
        new OA\Property(property: 'quantity_returned', type: 'string', example: '0.0000'),
        new OA\Property(property: 'quantity_invoiced', type: 'string', example: '0.0000'),
        new OA\Property(property: 'uom', type: 'string', example: 'PCE'),
        new OA\Property(property: 'unit_price', type: 'string', example: '12.5000'),
        new OA\Property(property: 'list_unit_price', type: 'string', nullable: true),
        new OA\Property(property: 'tax_rate', type: 'string', example: '22.00'),
        new OA\Property(property: 'line_net_amount', type: 'string', example: '125.00'),
        new OA\Property(property: 'line_tax_amount', type: 'string', example: '27.50'),
        new OA\Property(property: 'line_total_amount', type: 'string', example: '152.50'),
        new OA\Property(property: 'is_closed', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementPurchaseOrder',
    title: 'Purchase Order',
    description: 'Purchase order of the procurement module (PurchaseOrderResource). `supplier`, `lines` and `receipts` appear when the endpoint loads them.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'po_number', type: 'string', example: 'PO-2027-0001', description: 'Gapless number of the purchase_order series, shared with the purchasing API of the app'),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'sent', 'partially_received', 'received', 'closed', 'cancelled'], example: 'approved'),
        new OA\Property(property: 'order_type', type: 'string', nullable: true, enum: ['standard', 'blanket_release', 'subcontract']),
        new OA\Property(property: 'order_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expected_delivery_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'exchange_rate', type: 'string', example: '1.000000'),
        new OA\Property(property: 'net_amount', type: 'string', example: '12500.00'),
        new OA\Property(property: 'tax_amount', type: 'string', example: '2750.00'),
        new OA\Property(property: 'total_amount', type: 'string', example: '15250.00'),
        new OA\Property(property: 'is_budget_encumbered', type: 'boolean'),
        new OA\Property(property: 'budget_committed_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'supplier_contract_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'purchase_requisition_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'rfq_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'payment_terms_code', type: 'string', nullable: true),
        new OA\Property(property: 'incoterms', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'supplier', ref: '#/components/schemas/ProcurementSupplier', description: 'Present when the supplier is loaded'),
        new OA\Property(property: 'lines', type: 'array', description: 'Present when the lines are loaded', items: new OA\Items(ref: '#/components/schemas/ProcurementPurchaseOrderLine')),
        new OA\Property(property: 'receipts', type: 'array', description: 'Present when the receipts are loaded', items: new OA\Items(ref: '#/components/schemas/ProcurementGoodsReceipt')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementPurchaseRequisitionLine',
    description: 'Line of a purchase requisition (attributes of the model; quantities and prices are decimal strings)',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'purchase_requisition_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'line_number', type: 'integer'),
        new OA\Property(property: 'supplier_catalog_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity_requested', type: 'string', example: '10.0000'),
        new OA\Property(property: 'uom', type: 'string', example: 'PCE'),
        new OA\Property(property: 'estimated_unit_price', type: 'string', example: '45.0000'),
        new OA\Property(property: 'estimated_total_price', type: 'string', example: '450.00'),
        new OA\Property(property: 'suggested_supplier_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementPurchaseRequisition',
    title: 'Purchase Requisition',
    description: 'Internal spend request (PurchaseRequisitionResource); `lines` appears when the endpoint loads them. The total is a JSON number.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'requisition_number', type: 'string', example: 'REQ-20261028-001'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'sourcing', 'converted_to_po', 'cancelled'], example: 'draft'),
        new OA\Property(property: 'urgency', type: 'string', example: 'normal'),
        new OA\Property(property: 'required_by_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'total_estimated_amount', type: 'number', format: 'float', example: 4500.0),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'requestor_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementPurchaseRequisitionLine')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementGoodsReceiptLine',
    description: 'Line of a goods receipt; quantities and costs are decimal strings',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'purchase_order_line_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quantity_received', type: 'string', example: '10.0000'),
        new OA\Property(property: 'quantity_accepted', type: 'string', example: '10.0000'),
        new OA\Property(property: 'quantity_rejected', type: 'string', example: '0.0000'),
        new OA\Property(property: 'quantity_released', type: 'string', example: '10.0000'),
        new OA\Property(property: 'quantity_returned', type: 'string', example: '0.0000'),
        new OA\Property(property: 'unit_cost', type: 'string', nullable: true),
        new OA\Property(property: 'inspection_status', type: 'string', nullable: true),
        new OA\Property(property: 'lot_number', type: 'string', nullable: true),
        new OA\Property(property: 'serial_numbers', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'warehouse_bin_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'landed_cost_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementGoodsReceipt',
    title: 'Goods Receipt Note',
    description: 'Goods receipt of a purchase order (PurchaseReceiptResource); `lines` appears when the endpoint loads them.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'receipt_number', type: 'string', example: 'GRN-20261028-001'),
        new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_delivery_note_ref', type: 'string', nullable: true, example: 'DDT-991'),
        new OA\Property(property: 'received_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'received_by_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'inspection_required', type: 'boolean'),
        new OA\Property(property: 'inspection_status', type: 'string', nullable: true, enum: ['pending', 'passed', 'failed', 'conditional']),
        new OA\Property(property: 'inspected_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'landed_cost_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementGoodsReceiptLine')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementSupplierContract',
    title: 'Supplier Contract',
    description: 'Framework agreement of a supplier (SupplierContractResource). Amounts are JSON numbers.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_number', type: 'string', example: 'CTR-2026-001'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'contract_type', type: 'string', nullable: true),
        new OA\Property(property: 'total_committed_amount', type: 'number', format: 'float', example: 100000.0),
        new OA\Property(property: 'consumed_amount', type: 'number', format: 'float', example: 0.0),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'expired', 'terminated'], example: 'active'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementThreeWayMatchLog',
    title: 'Three Way Match Log',
    description: 'Result of the reconciliation of purchase order, goods receipt and supplier invoice (ThreeWayMatchLogResource). Amounts are JSON numbers; `override_approved` is set when a buyer approves the variance.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'purchase_receipt_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'match_status', type: 'string', enum: ['matched', 'price_variance', 'quantity_variance', 'escalated_to_workflow', 'override_approved'], example: 'matched'),
        new OA\Property(property: 'po_total_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'grn_total_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'invoice_total_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'variance_amount', type: 'number', format: 'float'),
        new OA\Property(property: 'variance_percentage', type: 'number', format: 'float'),
        new OA\Property(property: 'discrepancy_details', type: 'array', description: 'One entry per discrepancy found (empty when matched)', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'issue', type: 'string'),
            new OA\Property(property: 'invoice_net_amount', type: 'string'),
            new OA\Property(property: 'value_to_invoice', type: 'string'),
            new OA\Property(property: 'ordered_value', type: 'string'),
            new OA\Property(property: 'variance_amount', type: 'string'),
            new OA\Property(property: 'allowed_variance', type: 'string'),
        ])),
        new OA\Property(property: 'evaluated_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementPurchaseOrderPage',
    description: 'Paginated purchase orders',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementPurchaseOrder')),
        new OA\Property(property: 'links', ref: '#/components/schemas/ProcurementPaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementPaginationMetaFull'),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementPurchaseRequisitionPage',
    description: 'Paginated purchase requisitions',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementPurchaseRequisition')),
        new OA\Property(property: 'links', ref: '#/components/schemas/ProcurementPaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementPaginationMetaFull'),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementGoodsReceiptPage',
    description: 'Paginated goods receipts',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementGoodsReceipt')),
        new OA\Property(property: 'links', ref: '#/components/schemas/ProcurementPaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementPaginationMetaFull'),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementSupplierContractPage',
    description: 'Paginated supplier contracts',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementSupplierContract')),
        new OA\Property(property: 'links', ref: '#/components/schemas/ProcurementPaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementPaginationMetaFull'),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementThreeWayMatchLogPage',
    description: 'Paginated three-way match logs',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementThreeWayMatchLog')),
        new OA\Property(property: 'links', ref: '#/components/schemas/ProcurementPaginationLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementPaginationMetaFull'),
    ]
)]
final class ProcurementDocumentSchemas {}
