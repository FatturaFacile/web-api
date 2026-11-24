<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * OpenAPI schemas of the sales orders and quotes of the sales API (`/api/v1/sales/orders`, `/api/v1/sales/quotes`).
 *
 * The documents are written as stored: decimals are strings, dates are `Y-m-d`, the timestamps of the document are
 * `Y-m-d H:i:s` strings while those of the lines are ISO 8601. Delivered and invoiced quantities exist on order lines
 * only: quote lines do not carry them.
 */
#[OA\Schema(
    schema: 'SalesOrderHoldSummary',
    description: 'A hold placed on a sales order (the active ones are listed on the order). A credit hold is released with POST /api/v1/invoicing/customer-credit/holds/{holdId}/release, by the credit managers of the company (owner, admin, accountant, sales manager).',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Identifier to pass as {holdId} to the release endpoint'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'released', 'rejected'], description: 'Only active holds are listed'),
        new OA\Property(property: 'reason', type: 'string', example: 'Credit exposure 2100.00 exceeds the credit limit 1000.00'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SalesOrderLine',
    description: 'A sales order line. Quantities are decimal strings with four decimals, amounts two or four.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string', example: '2.0000'),
        new OA\Property(property: 'unit_price', type: 'string', example: '100.0000'),
        new OA\Property(property: 'tax_rate', type: 'string', example: '22.00'),
        new OA\Property(property: 'discount_percentage', type: 'string', example: '0.00'),
        new OA\Property(property: 'line_total', type: 'string', example: '200.00', description: 'Net total of the line, discount applied'),
        new OA\Property(property: 'quantity_delivered', type: 'string', example: '0.0000', description: 'Shipped through delivery notes'),
        new OA\Property(property: 'quantity_reserved', type: 'string', example: '0.0000', description: 'Reserved in the inventory module'),
        new OA\Property(property: 'quantity_invoiced', type: 'string', example: '0.0000', description: 'Invoiced so far'),
        new OA\Property(property: 'tax_code_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_price', type: 'string', example: '0.0000'),
        new OA\Property(property: 'uom', type: 'string', example: 'EA'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SalesOrder',
    description: 'A sales order as stored, with the contact summary and the active credit holds. The lines (`items`) are present on the single order endpoints and on creation, not in the list.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'order_number', type: 'string', example: 'SO-2026-0001', description: 'Gapless number of the order series'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'total_net', type: 'string', example: '200.00'),
        new OA\Property(property: 'total_tax', type: 'string', example: '44.00'),
        new OA\Property(property: 'total_gross', type: 'string', example: '244.00'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'confirmed', 'on_hold', 'approved', 'in_production', 'in_fulfillment', 'shipped', 'completed', 'cancelled', 'rejected'], description: 'on_hold is a credit hold: release it with the id in credit_hold_id'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', example: '2026-03-15 10:00:00', nullable: true, description: 'Y-m-d H:i:s'),
        new OA\Property(property: 'updated_at', type: 'string', example: '2026-03-15 10:00:00', nullable: true, description: 'Y-m-d H:i:s'),
        new OA\Property(property: 'subtotal', type: 'string', example: '0.00'),
        new OA\Property(property: 'discount_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'discount_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'customer_agreement_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sales_rep_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'credit_status', type: 'string', example: 'approved', description: 'not_checked, approved, warning or on_hold'),
        new OA\Property(property: 'credit_hold_reason', type: 'string', nullable: true),
        new OA\Property(property: 'inventory_status', type: 'string', example: 'pending'),
        new OA\Property(property: 'fulfillment_status', type: 'string', example: 'unfulfilled'),
        new OA\Property(property: 'billing_status', type: 'string', example: 'unbilled'),
        new OA\Property(property: 'payment_terms', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_method', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'billing_address', type: 'string', nullable: true),
        new OA\Property(property: 'confirmed_at', type: 'string', example: '2026-03-15 10:00:00', nullable: true),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', example: '2026-03-15 10:00:00', nullable: true),
        new OA\Property(property: 'contact', type: 'object', description: 'Present when the contact is loaded (list, show, create)', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
        ]),
        new OA\Property(property: 'credit_hold_id', type: 'string', format: 'uuid', nullable: true, description: 'Id of the active credit hold, null when the order is not on hold; the {holdId} of the release endpoint'),
        new OA\Property(property: 'holds', type: 'array', description: 'Active holds of the order (empty when none)', items: new OA\Items(ref: '#/components/schemas/SalesOrderHoldSummary')),
        new OA\Property(property: 'items', type: 'array', description: 'Lines of the order; absent from the list', items: new OA\Items(ref: '#/components/schemas/SalesOrderLine')),
    ]
)]
#[OA\Schema(
    schema: 'SalesOrderResponse',
    description: 'Envelope of a single sales order',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Order retrieved successfully'),
        new OA\Property(property: 'data', ref: '#/components/schemas/SalesOrder'),
    ]
)]
#[OA\Schema(
    schema: 'SalesOrderListResponse',
    description: 'Page of sales orders, newest document date first. `meta` is the short pagination block of the sales API (not the Laravel paginator).',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Orders retrieved successfully'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesOrder')),
        new OA\Property(property: 'meta', ref: '#/components/schemas/SalesShortPaginationMeta'),
    ]
)]
#[OA\Schema(
    schema: 'SalesShortPaginationMeta',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 4),
        new OA\Property(property: 'per_page', type: 'integer', example: 15),
        new OA\Property(property: 'total', type: 'integer', example: 52),
    ]
)]
#[OA\Schema(
    schema: 'SalesOrderDeleteResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Order deleted successfully'),
        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
    ]
)]
#[OA\Schema(
    schema: 'SalesQuoteLine',
    description: 'A quote line. Unlike order lines it has no delivered, reserved or invoiced quantity: those start with the order.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string', example: '2.0000'),
        new OA\Property(property: 'unit_price', type: 'string', example: '100.0000'),
        new OA\Property(property: 'tax_rate', type: 'string', example: '22.00'),
        new OA\Property(property: 'discount_percentage', type: 'string', example: '0.00'),
        new OA\Property(property: 'line_total', type: 'string', example: '200.00'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'tax_code_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_price', type: 'string', example: '0.0000'),
        new OA\Property(property: 'margin_percentage', type: 'string', example: '0.00'),
        new OA\Property(property: 'uom', type: 'string', example: 'EA'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'SalesQuote',
    description: 'A quote as stored, with the contact summary. Lines (`items`) are present on the single quote endpoints and on creation. The creation response carries the columns written by the request (plus id, number, totals and timestamps) and not those left at their database default; read the quote again for the full document.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_number', type: 'string', example: 'QT-2026-0001'),
        new OA\Property(property: 'date', type: 'string', example: '2026-03-15', description: 'Y-m-d (Y-m-d H:i:s in the creation response)'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'total_net', type: 'string', example: '200.00'),
        new OA\Property(property: 'total_tax', type: 'string', example: '44.00'),
        new OA\Property(property: 'total_gross', type: 'string', example: '244.00'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'pending_approval', 'approved', 'sent', 'accepted', 'rejected', 'expired', 'superseded']),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', example: '2026-03-15 10:00:00', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', example: '2026-03-15 10:00:00', nullable: true),
        new OA\Property(property: 'custom_text', type: 'string', nullable: true),
        new OA\Property(property: 'signed_at', type: 'string', nullable: true),
        new OA\Property(property: 'signed_by', type: 'string', nullable: true),
        new OA\Property(property: 'subtotal', type: 'string', example: '0.00'),
        new OA\Property(property: 'discount_amount', type: 'string', example: '0.00'),
        new OA\Property(property: 'discount_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'payment_terms', type: 'string', nullable: true),
        new OA\Property(property: 'shipping_terms', type: 'string', nullable: true),
        new OA\Property(property: 'revision_number', type: 'integer', example: 1),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', nullable: true),
        new OA\Property(property: 'converted_to_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'revision_of_quote_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'superseded_at', type: 'string', nullable: true),
        new OA\Property(property: 'contact', type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
        ]),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesQuoteLine')),
    ]
)]
#[OA\Schema(
    schema: 'SalesQuoteResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Quote retrieved successfully'),
        new OA\Property(property: 'data', ref: '#/components/schemas/SalesQuote'),
    ]
)]
#[OA\Schema(
    schema: 'SalesQuoteListResponse',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Quotes retrieved successfully'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SalesQuote')),
        new OA\Property(property: 'meta', ref: '#/components/schemas/SalesShortPaginationMeta'),
    ]
)]
#[OA\Schema(
    schema: 'SalesDocumentLineInput',
    description: 'A line of a sales order or quote as accepted on write',
    required: ['description', 'quantity', 'unit_price', 'tax_rate'],
    properties: [
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true, description: 'Product of the active company'),
        new OA\Property(property: 'description', type: 'string', maxLength: 1000),
        new OA\Property(property: 'quantity', type: 'number', exclusiveMinimum: 0, example: 2),
        new OA\Property(property: 'unit_price', type: 'number', minimum: 0, example: 100),
        new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, maximum: 100, example: 22),
        new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true, example: 0),
    ]
)]
#[OA\Schema(
    schema: 'SalesOrderWriteRequest',
    description: 'Create (contact_id, date, currency and items required) or update (every field optional) of a sales order. The number is assigned by the order series and cannot be sent. status: only draft or confirmed are meant to be set by hand; a confirmed order goes through the credit check and can come back on_hold.',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true, description: 'On or after the date'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'EUR'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'confirmed', 'in_production', 'shipped', 'completed', 'cancelled']),
        new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(ref: '#/components/schemas/SalesDocumentLineInput')),
    ]
)]
#[OA\Schema(
    schema: 'SalesQuoteWriteRequest',
    description: 'Create (contact_id, date, currency and items required) or update (every field optional) of a quote. The number is assigned by the quote series.',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true, description: 'On or after the date'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, example: 'EUR'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'sent', 'accepted', 'rejected', 'expired'], description: 'The other quote statuses (pending_approval, approved, superseded) are reached through the approval and revision endpoints'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'custom_text', type: 'string', maxLength: 5000, nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(ref: '#/components/schemas/SalesDocumentLineInput')),
    ]
)]
final class SalesDocumentSchemas {}
