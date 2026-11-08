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
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'price_list_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'discount_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'payment_terms', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'shipping_terms', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['description', 'quantity', 'tax_rate'], properties: [new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string', maxLength: 255), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'unit_price', type: 'number', minimum: 0, nullable: true, description: 'Omitted for a product: priced by the price determination (price lists, customer list, promotions)'), new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, maximum: 100), new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'uom', type: 'string', maxLength: 20, nullable: true)])),
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
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'payment_terms', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'shipping_method', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'billing_address', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'auto_submit', type: 'boolean', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['description', 'quantity', 'tax_rate'], properties: [new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'description', type: 'string', maxLength: 255), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'unit_price', type: 'number', minimum: 0, nullable: true, description: 'Omitted for a product: priced by the price determination (price lists, customer list, promotions)'), new OA\Property(property: 'tax_rate', type: 'number', minimum: 0, maximum: 100), new OA\Property(property: 'discount_percentage', type: 'number', minimum: 0, maximum: 100, nullable: true), new OA\Property(property: 'uom', type: 'string', maxLength: 20, nullable: true)])),
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
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'transport_reason', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'goods_appearance', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'packages_count', type: 'integer', minimum: 1, nullable: true),
        new OA\Property(property: 'gross_weight_kg', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'carrier_name', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'carrier_license_plate', type: 'string', maxLength: 30, nullable: true),
        new OA\Property(property: 'net_weight_kg', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'departure_time', type: 'string', format: 'date-time', nullable: true, description: 'Start of transport; the dispatch time when omitted'),
        new OA\Property(property: 'shipping_terms', type: 'string', maxLength: 100, nullable: true, example: 'Franco', description: 'Porto, as free text'),
        new OA\Property(property: 'transport_by', type: 'string', enum: ['sender', 'recipient', 'carrier'], nullable: true, description: 'Who carries the goods'),
        new OA\Property(property: 'shipping_address', type: 'string', nullable: true),
        new OA\Property(property: 'courier', type: 'string', maxLength: 255, nullable: true),
        new OA\Property(property: 'tracking_number', type: 'string', maxLength: 100, nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'items', type: 'array', minItems: 1, description: 'Order lines shipped, each within its open quantity (ordered less delivered)', items: new OA\Items(type: 'object', required: ['sales_order_item_id', 'quantity'], properties: [new OA\Property(property: 'sales_order_item_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity', type: 'number', minimum: 0.0001), new OA\Property(property: 'batch_number', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'serial_number', type: 'string', maxLength: 100, nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingCreateInvoiceRequest',
    description: 'Request payload validated by CreateInvoiceRequest',
    required: ['contact_id', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'invoice_type', type: 'string', nullable: true, enum: ['standard', 'credit_note', 'proforma']),
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'exchange_rate', type: 'number', minimum: 1.0E-6, nullable: true),
        new OA\Property(property: 'jurisdiction', type: 'string', nullable: true, enum: ['IT', 'CH']),
        new OA\Property(property: 'split_payment', type: 'boolean', nullable: true),
        new OA\Property(property: 'reverse_charge', type: 'boolean', nullable: true),
        new OA\Property(property: 'document_type', description: 'FatturaPA TipoDocumento. TD01 when omitted; TD04 only on a credit note.', type: 'string', nullable: true, enum: ['TD01', 'TD02', 'TD03', 'TD04', 'TD05', 'TD06', 'TD16', 'TD17', 'TD18', 'TD19', 'TD20', 'TD21', 'TD22', 'TD23', 'TD24', 'TD25', 'TD26', 'TD27', 'TD28', 'TD29']),
        new OA\Property(property: 'vat_nature', description: 'VAT nature of the zero-rated lines (N1-N7, without the parents N2, N3, N6).', type: 'string', nullable: true),
        new OA\Property(property: 'source_passive_invoice_id', description: 'The received invoice a self-invoice integrates.', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'linked_document_number', description: 'Reference for DatiFattureCollegate when no stored document is linked.', type: 'string', maxLength: 20, nullable: true),
        new OA\Property(property: 'linked_document_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'declaration_of_intent_id', description: 'Declaration of intent to use. An active one of the customer is applied automatically.', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'skip_declaration_of_intent', description: 'Issue with VAT even though the customer has an active declaration.', type: 'boolean', nullable: true),
        new OA\Property(property: 'withholding_tax_enabled', type: 'boolean', nullable: true),
        new OA\Property(property: 'withholding_tax_percent', type: 'number', nullable: true),
        new OA\Property(property: 'withholding_tax_type', type: 'string', nullable: true, enum: ['RT01', 'RT02', 'RT03', 'RT04', 'RT05', 'RT06']),
        new OA\Property(property: 'withholding_tax_reason', description: 'CausalePagamento of the CU form.', type: 'string', nullable: true),
        new OA\Property(property: 'withholding_tax_base_percent', description: 'Share of the amount subject to withholding, 100 when omitted.', type: 'number', nullable: true),
        new OA\Property(property: 'stamp_duty_applied', type: 'boolean', nullable: true),
        new OA\Property(property: 'stamp_duty_charged', description: 'Whether the stamp duty is charged to the customer (default true).', type: 'boolean', nullable: true),
        new OA\Property(property: 'fund_enabled', description: 'Pension fund contribution (DatiCassaPrevidenziale).', type: 'boolean', nullable: true),
        new OA\Property(property: 'fund_type', description: 'TipoCassa, TC22 for the INPS rivalsa.', type: 'string', nullable: true),
        new OA\Property(property: 'fund_percent', type: 'number', nullable: true),
        new OA\Property(property: 'fund_vat_rate', description: 'VAT rate of the contribution, the highest of the lines when omitted.', type: 'number', nullable: true),
        new OA\Property(property: 'fund_subject_to_withholding', type: 'boolean', nullable: true),
        new OA\Property(property: 'enasarco_enabled', description: 'Withhold the Enasarco contribution using the parameters of the year.', type: 'boolean', nullable: true),
        new OA\Property(property: 'enasarco_agent_type', type: 'string', nullable: true, enum: ['single_mandate', 'multi_mandate']),
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
    schema: 'InvoicingSaveDeclarationOfIntentRequest',
    description: 'Request payload validated by SaveDeclarationOfIntentRequest',
    required: ['contact_id', 'protocol_number', 'receipt_date', 'year', 'plafond_amount', 'valid_from', 'valid_to'],
    type: 'object',
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'direction', type: 'string', nullable: true, enum: ['received', 'issued']),
        new OA\Property(property: 'protocol_number', description: 'Receipt protocol and progressive.', type: 'string', example: '08060120341234567-000001'),
        new OA\Property(property: 'receipt_date', description: 'Date of the telematic receipt.', type: 'string', format: 'date'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'plafond_amount', type: 'number'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date'),
        new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingCreateInvoiceFromSourceRequest',
    description: 'Request payload validated by CreateInvoiceFromSourceRequest: the only fields a caller may state when an invoice is generated from a sales order or a delivery note. Customer, lines and number come from the source document and the numbering service.',
    type: 'object',
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'payment_method', type: 'string', maxLength: 4, nullable: true),
        new OA\Property(property: 'payment_terms', type: 'string', maxLength: 4, nullable: true),
        new OA\Property(property: 'bank_name', type: 'string', nullable: true),
        new OA\Property(property: 'bank_iban', type: 'string', maxLength: 34, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'InvoicingIssueCreditNoteRequest',
    description: 'Request payload validated by IssueCreditNoteRequest',
    required: ['original_invoice_id', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'original_invoice_id', type: 'string', format: 'uuid'),
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
