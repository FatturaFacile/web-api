<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the enterprise procurement endpoints (RFQ, landed costs, catalogue, agreements, suggestions).
 */
#[OA\Schema(schema: 'ProcurementDecimalString', description: 'Decimal number as a string', type: 'string', example: '1250.00')]
#[OA\Schema(
    schema: 'ProcurementRfqResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'rfq_number', type: 'string', example: 'RFQ-2027-0001'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'issued', 'awarded', 'cancelled']),
        new OA\Property(property: 'purchase_requisition_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'response_deadline', type: 'string', format: 'date'),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'awarded_supplier_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'awarded_quote_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'suppliers', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'quotes', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementSupplierQuoteResource')),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementSupplierQuoteResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'rfq_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_reference', type: 'string', nullable: true),
        new OA\Property(property: 'quote_date', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'lead_time_days', type: 'integer', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['received', 'awarded', 'rejected']),
        new OA\Property(property: 'total_amount', ref: '#/components/schemas/ProcurementDecimalString'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementLandedCostResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'landed_cost_number', type: 'string', example: 'LC-2027-0001'),
        new OA\Property(property: 'cost_type', type: 'string', enum: ['freight', 'duty', 'insurance', 'handling', 'other']),
        new OA\Property(property: 'allocation_method', type: 'string', enum: ['value', 'weight', 'quantity']),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'posted', 'cancelled']),
        new OA\Property(property: 'amount', ref: '#/components/schemas/ProcurementDecimalString'),
        new OA\Property(property: 'cost_date', type: 'string', format: 'date'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'allocations', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementSupplierDocumentResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_type', type: 'string', example: 'iso_9001'),
        new OA\Property(property: 'reference', type: 'string', nullable: true),
        new OA\Property(property: 'issued_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_mandatory', type: 'boolean'),
        new OA\Property(property: 'is_expired', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementContractLineResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_contract_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_catalog_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'unit_price', ref: '#/components/schemas/ProcurementDecimalString'),
        new OA\Property(property: 'committed_quantity', type: 'string', nullable: true),
        new OA\Property(property: 'released_quantity', type: 'string'),
        new OA\Property(property: 'remaining_quantity', type: 'string', nullable: true),
        new OA\Property(property: 'price_valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'price_valid_to', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementCatalogResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_part_number', type: 'string'),
        new OA\Property(property: 'internal_sku', type: 'string', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'uom', type: 'string'),
        new OA\Property(property: 'lead_time_days', type: 'integer'),
        new OA\Property(property: 'minimum_order_quantity', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'prices', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementCatalogPrice')),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementCatalogPrice',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'min_quantity', type: 'string'),
        new OA\Property(property: 'unit_price', type: 'string'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementSuggestionResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'source', description: 'reorder_rule (inventory reorder rule), mrp (planned purchase order of MRP) or sales_order_line (bought for a sales order line)', type: 'string', enum: ['reorder_rule', 'mrp', 'sales_order_line']),
        new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_catalog_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'quantity', type: 'string'),
        new OA\Property(property: 'unit_price', type: 'string', nullable: true),
        new OA\Property(property: 'required_by_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'converted', 'dismissed']),
        new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementSettingsResource',
    properties: [
        new OA\Property(property: 'over_receipt_tolerance_percent', type: 'string', example: '5.00'),
        new OA\Property(property: 'under_receipt_tolerance_percent', type: 'string', example: '2.00'),
        new OA\Property(property: 'price_tolerance_percent', type: 'string', example: '1.00'),
        new OA\Property(property: 'price_tolerance_amount', type: 'string', example: '5.00'),
        new OA\Property(property: 'block_invoice_on_mismatch', type: 'boolean'),
        new OA\Property(property: 'landed_cost_clearing_account_code', type: 'string', example: '2015'),
    ]
)]
#[OA\Schema(
    schema: 'ProcurementApprovalMatrixResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'target', type: 'string', enum: ['purchase_order', 'purchase_requisition']),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
final class ProcurementEnterpriseSchemas {}
