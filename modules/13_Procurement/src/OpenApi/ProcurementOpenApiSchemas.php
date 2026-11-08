<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProcurementSupplier',
    title: 'Procurement Supplier',
    description: 'Enterprise supplier entity with vendor master metadata, financial terms, and approval status',
    required: ['id', 'tenant_id', 'supplier_code', 'company_name', 'status']
)]
class ProcurementOpenApiSchemas
{
    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c')]
    public string $tenant_id;

    #[OA\Property(example: 'SUP-0001')]
    public string $supplier_code;

    #[OA\Property(example: 'Acme Industrial Supplies GmbH')]
    public string $company_name;

    #[OA\Property(example: 'IT12345678901', nullable: true)]
    public ?string $vat_number;

    #[OA\Property(example: 'active', enum: ['active', 'suspended', 'blocked'])]
    public string $status;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(example: 'NET30', nullable: true)]
    public ?string $payment_terms_code;

    #[OA\Property(example: 'DAP', nullable: true)]
    public ?string $incoterms;

    #[OA\Property(example: 50000.00, nullable: true)]
    public ?float $credit_limit;

    #[OA\Property(example: 'A', enum: ['A', 'B', 'C', 'D'], nullable: true)]
    public ?string $rating;

    #[OA\Property(example: true)]
    public bool $is_approved_vendor;
}

#[OA\Schema(
    schema: 'ProcurementPurchaseOrder',
    title: 'Purchase Order',
    description: 'Approved or draft purchase commitment with encumbrance tracking',
    required: ['id', 'tenant_id', 'supplier_id', 'po_number', 'status', 'total_amount']
)]
class ProcurementPurchaseOrderSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(description: 'Gapless number of the purchase_order series, shared with the purchasing API of the app', example: 'PO-2027-0001')]
    public string $po_number;

    #[OA\Property(format: 'uuid')]
    public string $supplier_id;

    #[OA\Property(example: 'approved', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'sent', 'partially_received', 'received', 'closed', 'cancelled'])]
    public string $status;

    #[OA\Property(example: 'standard', enum: ['standard', 'blanket_release', 'subcontract'])]
    public string $order_type;

    #[OA\Property(example: 'EUR')]
    public string $currency;

    #[OA\Property(description: 'Decimal string', example: '12500.00')]
    public string $net_amount;

    #[OA\Property(description: 'Decimal string', example: '2750.00')]
    public string $tax_amount;

    #[OA\Property(description: 'Decimal string', example: '15250.00')]
    public string $total_amount;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $workflow_instance_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $supplier_contract_id;

    #[OA\Property(format: 'uuid', nullable: true)]
    public ?string $rfq_id;

    /** @var list<array<string, mixed>> */
    #[OA\Property(description: 'Order lines with ordered, received, returned and invoiced quantities (decimal strings)', type: 'array', items: new OA\Items(type: 'object'))]
    public array $lines;

    #[OA\Property(example: true)]
    public bool $is_budget_encumbered;

    #[OA\Property(example: false)]
    public bool $is_locked;
}

#[OA\Schema(
    schema: 'ProcurementPurchaseRequisition',
    title: 'Purchase Requisition',
    description: 'Internal spend request with multi-line items and department allocation',
    required: ['id', 'tenant_id', 'requisition_number', 'status']
)]
class ProcurementPurchaseRequisitionSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'REQ-20261028-001')]
    public string $requisition_number;

    #[OA\Property(example: 'draft', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'sourcing', 'converted_to_po', 'cancelled'])]
    public string $status;

    #[OA\Property(example: 4500.00)]
    public float $estimated_total;
}

#[OA\Schema(
    schema: 'ProcurementGoodsReceipt',
    title: 'Goods Receipt Note',
    description: 'Warehouse receiving slip with inspection and lot tracking',
    required: ['id', 'tenant_id', 'purchase_order_id', 'receipt_number']
)]
class ProcurementGoodsReceiptSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'GRN-20261028-001')]
    public string $receipt_number;

    #[OA\Property(format: 'uuid')]
    public string $purchase_order_id;

    #[OA\Property(example: 'DDT-991')]
    public string $supplier_delivery_note_ref;

    #[OA\Property(example: 'passed', enum: ['pending', 'passed', 'failed', 'conditional'])]
    public string $inspection_status;

    #[OA\Property(example: false)]
    public bool $inspection_required;

    /** @var list<array<string, mixed>> */
    #[OA\Property(description: 'Receipt lines with accepted, rejected, released and returned quantities, unit cost, lot and serial numbers', type: 'array', items: new OA\Items(type: 'object'))]
    public array $lines;
}

#[OA\Schema(
    schema: 'ProcurementSupplierContract',
    title: 'Supplier Contract',
    description: 'Framework agreement with contracted pricing and expiry terms',
    required: ['id', 'supplier_id', 'contract_number', 'status']
)]
class ProcurementSupplierContractSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $supplier_id;

    #[OA\Property(example: 'CTR-2026-001')]
    public string $contract_number;

    #[OA\Property(example: 'active', enum: ['draft', 'active', 'expired', 'terminated'])]
    public string $status;

    #[OA\Property(example: 100000.00)]
    public float $committed_value;
}

#[OA\Schema(
    schema: 'ProcurementThreeWayMatchLog',
    title: 'Three Way Match Log',
    description: 'Automated tolerance reconciliation between PO, GRN, and Vendor Invoice',
    required: ['id', 'purchase_order_id', 'supplier_id', 'status']
)]
class ProcurementThreeWayMatchLogSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(format: 'uuid')]
    public string $purchase_order_id;

    #[OA\Property(format: 'uuid')]
    public string $supplier_id;

    #[OA\Property(example: 'matched', enum: ['matched', 'discrepancy', 'escalated_to_workflow', 'rejected'])]
    public string $status;

    #[OA\Property(example: 0.00)]
    public float $price_variance;

    #[OA\Property(example: 0.00)]
    public float $quantity_variance;
}

#[OA\Schema(
    schema: 'ProcurementApiPaginationMeta',
    title: 'API Pagination Meta',
    description: 'Standard pagination metadata',
    required: ['current_page', 'per_page', 'total']
)]
class ProcurementApiPaginationMetaSchema
{
    #[OA\Property(example: 1)]
    public int $current_page;

    #[OA\Property(example: 25)]
    public int $per_page;

    #[OA\Property(example: 150)]
    public int $total;

    #[OA\Property(example: 6)]
    public int $last_page;
}

#[OA\Schema(
    schema: 'ProcurementApiErrorResponse',
    title: 'Procurement API Error Response',
    description: 'Standard error envelope for procurement endpoints',
    required: ['success', 'message']
)]
class ProcurementApiErrorResponseSchema
{
    #[OA\Property(example: false)]
    public bool $success;

    #[OA\Property(example: 'Resource not found or unauthorized')]
    public string $message;

    #[OA\Property(example: 401)]
    public int $status;
}

#[OA\Schema(
    schema: 'ProcurementSupplierScorecard',
    title: 'Supplier Scorecard',
    description: 'Quarterly supplier performance metrics, OTIF percentage, and AVL status',
    required: ['id', 'supplier_id', 'year', 'quarter', 'otif_percentage', 'risk_level']
)]
class ProcurementSupplierScorecardSchema
{
    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6c')]
    public string $supplier_id;

    #[OA\Property(example: 2026)]
    public int $year;

    #[OA\Property(example: 3)]
    public int $quarter;

    #[OA\Property(example: 98.5)]
    public float $otif_percentage;

    #[OA\Property(example: 25, nullable: true)]
    public ?int $defect_ppm;

    #[OA\Property(example: 12500.00)]
    public float $cost_savings_amount;

    #[OA\Property(example: 95.0)]
    public float $compliance_score;

    #[OA\Property(example: 'low', enum: ['low', 'medium', 'high', 'critical'])]
    public string $risk_level;

    #[OA\Property(example: true)]
    public bool $is_avl_active;

    #[OA\Property(type: 'object', nullable: true)]
    public ?array $scorecard_data;
}

#[OA\Schema(
    schema: 'ProcurementApiValidationErrorResponse',
    title: 'Procurement API Validation Error Response',
    description: 'Validation errors dictionary',
    required: ['message', 'errors']
)]
class ProcurementApiValidationErrorResponseSchema
{
    #[OA\Property(example: 'The given data was invalid.')]
    public string $message;

    #[OA\Property(type: 'object')]
    public array $errors;
}
