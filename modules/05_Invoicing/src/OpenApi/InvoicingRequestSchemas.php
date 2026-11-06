<?php

declare(strict_types=1);

namespace Modules\Invoicing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'InvoicingCreateSalesQuoteRequest',
    description: 'Request payload validated by CreateSalesQuoteRequest',
    required: ['contact_id', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_number', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'discount_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'payment_terms', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'shipping_terms', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['description', 'quantity'], properties: [new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string', maxLength: 255), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'unit_price', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'uom', type: 'string', maxLength: 20, nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingCreateSalesOrderRequest',
    description: 'Request payload validated by CreateSalesOrderRequest',
    required: ['contact_id', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'quote_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'customer_agreement_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'discount_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'sales_rep_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'order_number', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'payment_terms', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'shipping_method', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'billing_address', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'auto_submit', type: 'boolean', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['description', 'quantity'], properties: [new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string', maxLength: 255), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'unit_price', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'uom', type: 'string', maxLength: 20, nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingCreateDeliveryNoteRequest',
    description: 'Request payload validated by CreateDeliveryNoteRequest',
    required: ['sales_order_id', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'ddt_number', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'transport_reason', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'goods_appearance', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'packages_count', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'gross_weight_kg', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'carrier_name', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'carrier_license_plate', type: 'string', maxLength: 30, nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'courier', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'tracking_number', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['sales_order_item_id', 'quantity'], properties: [new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'batch_number', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'serial_number', type: 'string', maxLength: 100, nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingCreateInvoiceRequest',
    description: 'Request payload validated by CreateInvoiceRequest',
    required: ['contact_id', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_number', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'invoice_type', type: 'string', nullable: true, enum: ['standard', 'credit_note', 'proforma']),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'exchange_rate', type: 'number', minimum: 1.0E-6, nullable: true),
        new OA\Property(property: 'jurisdiction', type: 'string', nullable: true, enum: ['IT', 'CH']),
        new OA\Property(property: 'split_payment', type: 'boolean', nullable: true),
        new OA\Property(property: 'reverse_charge', type: 'boolean', nullable: true),
        new OA\Property(property: 'payment_method', type: 'string', maxLength: 4, nullable: true),
        new OA\Property(property: 'payment_terms', type: 'string', maxLength: 4, nullable: true),
        new OA\Property(property: 'bank_name', type: 'string', nullable: true),
        new OA\Property(property: 'bank_iban', type: 'string', maxLength: 34, nullable: true),
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'delivery_note_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_milestone_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['description', 'quantity', 'unit_price'], properties: [new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'delivery_note_item_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string', maxLength: 255), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'unit_price', type: 'number', minimum: 0), new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'tax_account_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'uom', type: 'string', maxLength: 20, nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingIssueCreditNoteRequest',
    description: 'Request payload validated by IssueCreditNoteRequest',
    required: ['original_invoice_id', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'original_invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_number', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['description', 'quantity', 'unit_price'], properties: [new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string', maxLength: 255), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'unit_price', type: 'number', minimum: 0), new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, nullable: true), new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingUpdateCreditLimitRequest',
    description: 'Request payload validated by UpdateCreditLimitRequest',
    required: ['customer_id', 'credit_limit'],
    type: 'object',
    properties: [
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'credit_limit', type: 'number', minimum: 0),
    ]
)]
final class InvoicingRequestSchemas {}
