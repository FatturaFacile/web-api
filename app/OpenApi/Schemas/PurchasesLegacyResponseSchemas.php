<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Response schemas of the purchasing API of the app (`/purchases/*`) and of the delivery notes list of the inventory API.
 *
 * Their lists are NOT resource collections: the controller puts the Laravel paginator itself in the `data` of the
 * `{success, message, data}` envelope, so the rows are in `data.data` (double wrapped) and the page numbers are
 * directly in `data` (`current_page`, `total`...). The rows are the serialised models: every column of the table is
 * present. The goods receipts list is the exception: a plain `data` array with a short `meta`.
 */
#[OA\Schema(
    schema: 'PurchasesPageLink',
    properties: [
        new OA\Property(property: 'url', type: 'string', nullable: true),
        new OA\Property(property: 'label', type: 'string', example: '1'),
        new OA\Property(property: 'page', type: 'integer', nullable: true),
        new OA\Property(property: 'active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesPaginator',
    description: 'Laravel paginator serialised as is (without its rows, which are in `data` of the schemas that extend it)',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'first_page_url', type: 'string'),
        new OA\Property(property: 'from', type: 'integer', nullable: true),
        new OA\Property(property: 'last_page', type: 'integer'),
        new OA\Property(property: 'last_page_url', type: 'string'),
        new OA\Property(property: 'links', type: 'array', items: new OA\Items(ref: '#/components/schemas/PurchasesPageLink')),
        new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'per_page', type: 'integer', example: 25),
        new OA\Property(property: 'prev_page_url', type: 'string', nullable: true),
        new OA\Property(property: 'to', type: 'integer', nullable: true),
        new OA\Property(property: 'total', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesSupplierContact',
    description: 'Supplier as a contact record (all the columns of the contact)',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', example: 'supplier'),
        new OA\Property(property: 'country', type: 'string', example: 'IT'),
        new OA\Property(property: 'company_name', type: 'string', nullable: true),
        new OA\Property(property: 'first_name', type: 'string', nullable: true),
        new OA\Property(property: 'last_name', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'vat_number', type: 'string', nullable: true),
        new OA\Property(property: 'fiscal_code', type: 'string', nullable: true),
        new OA\Property(property: 'uid_number', type: 'string', nullable: true),
        new OA\Property(property: 'sdi_code', type: 'string', nullable: true),
        new OA\Property(property: 'pec', type: 'string', nullable: true),
        new OA\Property(property: 'iban', type: 'string', nullable: true),
        new OA\Property(property: 'qr_iban', type: 'string', nullable: true),
        new OA\Property(property: 'payment_terms_days', type: 'integer', nullable: true),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'payment_term_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'document_language', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesOrderItem',
    description: 'Line of a purchase order of the purchasing API; quantities and amounts are decimal strings',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string', example: '10.0000'),
        new OA\Property(property: 'quantity_received', type: 'string', example: '0.0000'),
        new OA\Property(property: 'unit_price', type: 'string', example: '40.0000'),
        new OA\Property(property: 'tax_rate', type: 'string', example: '22.00'),
        new OA\Property(property: 'line_total', type: 'string', example: '400.00'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesOrderRow',
    description: 'Row of the purchase orders list: every column of the order (the table is shared with the procurement module, hence its extra columns) with its supplier and lines. Totals are decimal strings; dates are ISO date-times at midnight UTC.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'purchase_request_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'order_number', type: 'string', example: 'PO-2026-0001'),
        new OA\Property(property: 'date', type: 'string', format: 'date-time'),
        new OA\Property(property: 'delivery_due_date', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'draft'),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'total_net', type: 'string', example: '400.00'),
        new OA\Property(property: 'total_tax', type: 'string', example: '88.00'),
        new OA\Property(property: 'total_gross', type: 'string', example: '488.00'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'company_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'branch_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'supplier_contract_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'purchase_requisition_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'po_number', type: 'string', nullable: true, description: 'Number of the procurement series; null for orders created here'),
        new OA\Property(property: 'order_type', type: 'string', example: 'standard'),
        new OA\Property(property: 'expected_delivery_date', type: 'string', nullable: true),
        new OA\Property(property: 'exchange_rate', type: 'string', example: '1.000000'),
        new OA\Property(property: 'net_amount', type: 'string'),
        new OA\Property(property: 'tax_amount', type: 'string'),
        new OA\Property(property: 'total_amount', type: 'string'),
        new OA\Property(property: 'budget_committed_amount', type: 'string'),
        new OA\Property(property: 'payment_terms_code', type: 'string', nullable: true),
        new OA\Property(property: 'incoterms', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'is_budget_encumbered', type: 'boolean'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'updated_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'rfq_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'supplier', ref: '#/components/schemas/PurchasesSupplierContact'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/PurchasesOrderItem')),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesRequestUser',
    description: 'User who raised a purchase request (all the user columns except the secrets)',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'email', type: 'string'),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'locale', type: 'string', example: 'it'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'first_name', type: 'string', nullable: true),
        new OA\Property(property: 'last_name', type: 'string', nullable: true),
        new OA\Property(property: 'is_platform_admin', type: 'boolean'),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesRequestRow',
    description: 'Row of the purchase requests list. `total_estimated` is a decimal string; `requested_date` an ISO date-time at midnight UTC.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'request_number', type: 'string', example: 'PR-2026-0001'),
        new OA\Property(property: 'department', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', example: 'draft'),
        new OA\Property(property: 'total_estimated', type: 'string', example: '10.00'),
        new OA\Property(property: 'requested_date', type: 'string', format: 'date-time'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'user', ref: '#/components/schemas/PurchasesRequestUser'),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesDeliveryNoteRow',
    description: 'Row of the delivery notes (DDT) list: the columns of the delivery note, without its lines. Weights are decimal strings; `date` is an ISO date-time at midnight UTC.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'ddt_number', type: 'string', example: 'DDT-2026-0001'),
        new OA\Property(property: 'date', type: 'string', format: 'date-time'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'shipping_status', type: 'string'),
        new OA\Property(property: 'transport_reason', type: 'string', nullable: true),
        new OA\Property(property: 'transport_by', type: 'string', nullable: true),
        new OA\Property(property: 'goods_appearance', type: 'string', nullable: true),
        new OA\Property(property: 'packages_count', type: 'integer', nullable: true),
        new OA\Property(property: 'gross_weight_kg', type: 'string', nullable: true),
        new OA\Property(property: 'net_weight_kg', type: 'string', nullable: true),
        new OA\Property(property: 'carrier_name', type: 'string', nullable: true),
        new OA\Property(property: 'carrier_license_plate', type: 'string', nullable: true),
        new OA\Property(property: 'courier', type: 'string', nullable: true),
        new OA\Property(property: 'tracking_number', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_terms', type: 'string', nullable: true),
        new OA\Property(property: 'show_prices', type: 'boolean'),
        new OA\Property(property: 'departure_time', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'shipped_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'delivered_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesGoodsReceiptRow',
    description: 'Row of the goods receipts list of the purchasing API (columns of the receipt, without lines)',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'purchase_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'receipt_number', type: 'string', example: 'GR-2026-0001'),
        new OA\Property(property: 'date', type: 'string', format: 'date-time'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesOrdersListResponse',
    description: 'GET /purchases/orders: the paginator is inside `data`, so the orders are in `data.data`',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Purchase orders retrieved'),
        new OA\Property(property: 'data', allOf: [
            new OA\Schema(ref: '#/components/schemas/PurchasesPaginator'),
            new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PurchasesOrderRow'))], type: 'object'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesRequestsListResponse',
    description: 'GET /purchases/requests: the paginator is inside `data`, so the requests are in `data.data`',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Purchase requests retrieved'),
        new OA\Property(property: 'data', allOf: [
            new OA\Schema(ref: '#/components/schemas/PurchasesPaginator'),
            new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PurchasesRequestRow'))], type: 'object'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesDeliveryNotesListResponse',
    description: 'GET /inventory/delivery-notes: the paginator is inside `data`, so the delivery notes are in `data.data`',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Delivery notes (DDT) retrieved'),
        new OA\Property(property: 'data', allOf: [
            new OA\Schema(ref: '#/components/schemas/PurchasesPaginator'),
            new OA\Schema(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PurchasesDeliveryNoteRow'))], type: 'object'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'PurchasesGoodsReceiptsListResponse',
    description: 'GET /purchases/goods-receipts: a plain list in `data` (not double wrapped) with a short `meta`',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Goods receipts retrieved successfully'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PurchasesGoodsReceiptRow')),
        new OA\Property(property: 'meta', properties: [
            new OA\Property(property: 'current_page', type: 'integer'),
            new OA\Property(property: 'last_page', type: 'integer'),
            new OA\Property(property: 'per_page', type: 'integer', example: 15),
            new OA\Property(property: 'total', type: 'integer'),
        ], type: 'object'),
    ]
)]
final class PurchasesLegacyResponseSchemas {}
