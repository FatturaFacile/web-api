<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'GeneralLedgerChartOfAccountResource',
    description: 'JSON representation produced by ChartOfAccountResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'account_code'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'account_class'),
        new OA\Property(property: 'account_type'),
        new OA\Property(property: 'normal_balance'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'is_reconciliation_account', type: 'boolean'),
        new OA\Property(property: 'reconciliation_type'),
        new OA\Property(property: 'allows_manual_posting', type: 'boolean'),
        new OA\Property(property: 'is_cost_center_mandatory', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerFiscalYearResource',
    description: 'JSON representation produced by FiscalYearResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'year_code'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'periods', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerFiscalPeriodResource')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerFiscalPeriodResource',
    description: 'JSON representation produced by FiscalPeriodResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'fiscal_year_id', type: 'string'),
        new OA\Property(property: 'period_number', type: 'integer'),
        new OA\Property(property: 'period_name'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerFinancialClosingRunModel',
    description: 'Attributes of the Financial Closing Run record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'net_income_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'executed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerJournalEntryResource',
    description: 'JSON representation produced by JournalEntryResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'fiscal_period_id', type: 'string'),
        new OA\Property(property: 'batch_id', type: 'string'),
        new OA\Property(property: 'entry_number'),
        new OA\Property(property: 'posting_date', type: 'string', format: 'date'),
        new OA\Property(property: 'document_date', type: 'string', format: 'date'),
        new OA\Property(property: 'ledger_type'),
        new OA\Property(property: 'journal_type'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'source_document_type'),
        new OA\Property(property: 'source_document_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'total_amount', type: 'number'),
        new OA\Property(property: 'posted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'reversed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'reversal_journal_entry_id', type: 'string'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerJournalLineResource')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerJournalLineResource',
    description: 'JSON representation produced by JournalLineResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'line_number', type: 'integer'),
        new OA\Property(property: 'account_id', type: 'string'),
        new OA\Property(property: 'cost_center_id', type: 'string'),
        new OA\Property(property: 'profit_center_id', type: 'string'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'partner_type'),
        new OA\Property(property: 'partner_id', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'debit_amount', type: 'number'),
        new OA\Property(property: 'credit_amount', type: 'number'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'exchange_rate', type: 'number'),
        new OA\Property(property: 'amount_in_functional_currency', type: 'number'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerReconciliationRunResource',
    description: 'JSON representation produced by ReconciliationRunResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'reconciliation_type'),
        new OA\Property(property: 'period_id', type: 'string'),
        new OA\Property(property: 'account_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'gl_balance', type: 'number'),
        new OA\Property(property: 'subledger_balance', type: 'number'),
        new OA\Property(property: 'variance_amount', type: 'number'),
        new OA\Property(property: 'reconciled_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'notes', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerCustomerLedgerModel',
    description: 'Attributes of the Customer Ledger record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'original_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'open_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'dunning_level', type: 'integer', nullable: true),
        new OA\Property(property: 'last_dunning_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerSupplierLedgerModel',
    description: 'Attributes of the Supplier Ledger record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'posting_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'original_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'open_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerVatSettlementResource',
    description: 'JSON representation produced by VatSettlementResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'fiscal_period_id', type: 'string'),
        new OA\Property(property: 'settlement_period'),
        new OA\Property(property: 'output_vat_amount', type: 'number'),
        new OA\Property(property: 'input_vat_amount', type: 'number'),
        new OA\Property(property: 'net_vat_payable', type: 'number'),
        new OA\Property(property: 'net_vat_credit', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'settlement_journal_id', type: 'string'),
    ]
)]
final class GeneralLedgerResponseSchemas {}
