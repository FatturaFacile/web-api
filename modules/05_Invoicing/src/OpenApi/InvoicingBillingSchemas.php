<?php

declare(strict_types=1);

namespace Modules\Invoicing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the billing endpoints of the invoicing module: billing overrides, billing plans,
 * billing runs, payment schedules, sales register and receivables aging.
 */
#[OA\Schema(
    schema: 'InvoicingBillingOverrides',
    description: 'What a caller may state on an invoice generated from a source document. The number is always assigned by the server.',
    properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'notes', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'payment_method', description: 'FatturaPA ModalitaPagamento code', type: 'string', maxLength: 4, nullable: true),
        new OA\Property(property: 'payment_terms', description: 'FatturaPA CondizioniPagamento code', type: 'string', maxLength: 4, nullable: true),
        new OA\Property(property: 'bank_name', type: 'string', nullable: true),
        new OA\Property(property: 'bank_iban', type: 'string', maxLength: 34, nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'InvoicingBillingPlanResource',
    description: 'A billing plan (milestone billing) of a sales order. Amounts are decimal strings.',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'schedule_number', type: 'string'),
        new OA\Property(property: 'sales_order_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'completed', 'cancelled']),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'total_amount', type: 'string'),
        new OA\Property(property: 'billed_amount', type: 'string'),
        new OA\Property(property: 'remaining_amount', type: 'string'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'next_billing_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'milestone_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'percentage', type: 'string', nullable: true),
            new OA\Property(property: 'amount', type: 'string'),
            new OA\Property(property: 'target_date', type: 'string', format: 'date'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'invoiced', 'cancelled']),
            new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'invoiced_at', type: 'string', format: 'date-time', nullable: true),
        ], type: 'object')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'InvoicingBillingRunResult',
    description: 'Outcome of a billing run: the invoices issued and, per refused group, the reason.',
    properties: [
        new OA\Property(property: 'invoices', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingSalesInvoiceResource')),
        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'InvoicingInstallmentSchedule',
    description: 'Payment schedule (scadenze) of an issued document: each installment with what is paid and open, earliest first.',
    properties: [
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'installments', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'sequence', type: 'integer'),
            new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
            new OA\Property(property: 'amount', type: 'string'),
            new OA\Property(property: 'paid', type: 'string'),
            new OA\Property(property: 'open', type: 'string'),
        ], type: 'object')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'InvoicingSalesRegister',
    description: 'Sales register of a period from issued documents; VAT per rate from the posted journal entries.',
    properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
        new OA\Property(property: 'documents', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'invoice_number', type: 'string'),
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'document_type', type: 'string'),
            new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'customer_name', type: 'string', nullable: true),
            new OA\Property(property: 'customer_vat_number', type: 'string', nullable: true),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'exchange_rate', type: 'string'),
            new OA\Property(property: 'total_net', type: 'string'),
            new OA\Property(property: 'total_tax', type: 'string'),
            new OA\Property(property: 'total_gross', type: 'string'),
            new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'vat', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'rate', type: 'string'),
                new OA\Property(property: 'taxable', type: 'string'),
                new OA\Property(property: 'tax', type: 'string'),
            ], type: 'object')),
        ], type: 'object')),
        new OA\Property(property: 'totals', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'InvoicingReceivablesAging',
    description: 'Open receivables at a date per customer and currency, bucketed by days past due of each installment.',
    properties: [
        new OA\Property(property: 'as_of', type: 'string', format: 'date'),
        new OA\Property(property: 'customers', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'customer_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'customer_name', type: 'string', nullable: true),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'current', type: 'string'),
            new OA\Property(property: 'days_1_30', type: 'string'),
            new OA\Property(property: 'days_31_60', type: 'string'),
            new OA\Property(property: 'days_61_90', type: 'string'),
            new OA\Property(property: 'over_90', type: 'string'),
            new OA\Property(property: 'total_open', type: 'string'),
            new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
        ], type: 'object')),
        new OA\Property(property: 'totals', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'InvoicingPaginatorLinks',
    description: 'Links of the Laravel paginator; null at the first and last page',
    properties: [
        new OA\Property(property: 'first', type: 'string', nullable: true),
        new OA\Property(property: 'last', type: 'string', nullable: true),
        new OA\Property(property: 'prev', type: 'string', nullable: true),
        new OA\Property(property: 'next', type: 'string', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'InvoicingPaginatorMeta',
    description: 'Meta block of the Laravel paginator',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 3),
        new OA\Property(property: 'links', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'url', type: 'string', nullable: true),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'page', type: 'integer', nullable: true),
            new OA\Property(property: 'active', type: 'boolean'),
        ], type: 'object')),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'per_page', type: 'integer', example: 25),
        new OA\Property(property: 'to', type: 'integer', nullable: true, example: 25),
        new OA\Property(property: 'total', type: 'integer', example: 60),
    ],
    type: 'object'
)]
final class InvoicingBillingSchemas {}
