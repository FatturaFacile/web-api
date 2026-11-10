<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * OpenAPI schemas of the received supplier documents (`/api/v1/purchases/supplier-invoices`). The list answers with the
 * sales/purchases envelope (`success`, `message`, `data`) and the short `meta` pagination block.
 */
#[OA\Schema(
    schema: 'PurchasesSupplierInvoice',
    description: 'A received supplier document (passive invoice) as stored. Decimals are strings, dates are ISO 8601 date-times; the raw XML payload is never returned.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_number', type: 'string', example: 'FP-2026/118'),
        new OA\Property(property: 'date', type: 'string', format: 'date-time', example: '2026-03-01T00:00:00.000000Z'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'total_amount', type: 'string', example: '122.00'),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'sdi_lot_id', type: 'string', nullable: true),
        new OA\Property(property: 'is_reconciled', type: 'boolean'),
        new OA\Property(property: 'qr_raw_payload', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'document_type', type: 'string', example: 'TD01'),
        new OA\Property(property: 'total_net', type: 'string', example: '100.00'),
        new OA\Property(property: 'total_tax', type: 'string', example: '22.00'),
        new OA\Property(property: 'payment_status', type: 'string', example: 'unpaid'),
        new OA\Property(property: 'document_hash', type: 'string', nullable: true),
        new OA\Property(property: 'sdi_file_name', type: 'string', nullable: true),
        new OA\Property(property: 'total_paid', type: 'string', example: '0.00'),
        new OA\Property(property: 'gl_journal_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'exchange_rate', type: 'string', nullable: true, example: '1.000000'),
        new OA\Property(property: 'withholding_tax_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'withholdings', type: 'array', nullable: true, items: new OA\Items(type: 'object', additionalProperties: true)),
        new OA\Property(property: 'declaration_of_intent_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_account_source', type: 'string', nullable: true),
        new OA\Property(property: 'approval_status', type: 'string', example: 'not_required'),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approval_workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approval_submitted_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approval_decided_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'stamp_duty_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'match_status', type: 'string', nullable: true, description: 'Three-way match result: matched, price_variance, quantity_variance, escalated_to_workflow or override_approved'),
        new OA\Property(property: 'approval_status_before_block', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesSupplierInvoiceListResponse',
    description: 'Page of supplier invoices, newest document date first',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PurchasesSupplierInvoice')),
        new OA\Property(property: 'meta', ref: '#/components/schemas/SalesShortPaginationMeta'),
    ]
)]
final class SupplierInvoiceSchemas {}
