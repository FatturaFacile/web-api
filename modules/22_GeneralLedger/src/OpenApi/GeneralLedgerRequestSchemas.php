<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'GeneralLedgerStoreChartOfAccountRequest',
    description: 'Request payload validated by StoreChartOfAccountRequest',
    required: ['account_code', 'name', 'account_type', 'normal_balance'],
    type: 'object',
    properties: [
        new OA\Property(property: 'account_code', type: 'string', maxLength: 50),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'account_class', type: 'string', maxLength: 30, nullable: true),
        new OA\Property(property: 'account_type', type: 'string', enum: ['asset', 'liability', 'equity', 'revenue', 'expense', 'cogs', 'wip', 'memo', 'statistical']),
        new OA\Property(property: 'normal_balance', type: 'string', enum: ['debit', 'credit']),
        new OA\Property(property: 'parent_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'is_reconciliation_account', type: 'boolean', nullable: true),
        new OA\Property(property: 'reconciliation_type', type: 'string', maxLength: 20, nullable: true),
        new OA\Property(property: 'allows_manual_posting', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_cost_center_mandatory', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'reporting_class', type: 'string', nullable: true, enum: ['cash', 'receivable', 'inventory', 'other_current_asset', 'non_current_asset', 'current_liability', 'non_current_liability', 'equity', 'non_cash_expense'], description: 'Drives the cash flow statement and the ratios'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerCalculateTaxSettlementRequest',
    description: 'Request payload validated by CalculateTaxSettlementRequest: either a fiscal period, or a year and period number.',
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_period_id', type: 'string', format: 'uuid', nullable: true, description: 'Fiscal period spanning a calendar month, quarter or half year'),
        new OA\Property(property: 'period_type', type: 'string', enum: ['monthly', 'quarterly', 'semiannual'], nullable: true, description: 'Defaults to the periodicity of the VAT settings'),
        new OA\Property(property: 'year', type: 'integer', nullable: true, description: 'Required without `fiscal_period_id`'),
        new OA\Property(property: 'period', type: 'integer', minimum: 1, maximum: 12, nullable: true, description: 'Required without `fiscal_period_id`'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerPayTaxSettlementRequest',
    description: 'Request payload validated by PayTaxSettlementRequest',
    required: ['payment_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'payment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid', nullable: true, description: 'Ledger account of the bank: when given, the payment is registered in the journal'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerUpdateVatTaxSettingRequest',
    description: 'Request payload validated by UpdateVatTaxSettingRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'periodicity', type: 'string', enum: ['monthly', 'quarterly', 'semiannual'], nullable: true),
        new OA\Property(property: 'output_vat_account_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
        new OA\Property(property: 'input_vat_account_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
        new OA\Property(property: 'settlement_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'interest_expense_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'declarant_tax_code', type: 'string', maxLength: 16, nullable: true),
        new OA\Property(property: 'declarant_role_code', type: 'string', maxLength: 2, nullable: true),
        new OA\Property(property: 'carry_over_below_minimum', type: 'boolean', default: true, description: 'Italian periodic VAT: a debt not above EUR 100 moves to the next period (true, default) or is paid in its own period (false)'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreFiscalYearRequest',
    description: 'Request payload validated by StoreFiscalYearRequest',
    required: ['year_code', 'start_date', 'end_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'year_code', type: 'string', maxLength: 20),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', description: 'Must be after `start_date`.'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreFiscalPeriodRequest',
    description: 'Request payload validated by StoreFiscalPeriodRequest',
    required: ['fiscal_year_id', 'period_number', 'period_name', 'start_date', 'end_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_year_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'period_number', type: 'integer', minimum: 1, maximum: 13),
        new OA\Property(property: 'period_name', type: 'string', maxLength: 100),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', description: 'Must be after `start_date`.'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerReverseJournalEntryRequest',
    description: 'Request payload validated by ReverseJournalEntryRequest',
    required: ['reversal_date'],
    type: 'object',
    properties: [
        new OA\Property(property: 'reversal_date', type: 'string', format: 'date'),
        new OA\Property(property: 'reason', type: 'string', maxLength: 255, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreJournalEntryRequest',
    description: 'Request payload validated by StoreJournalEntryRequest',
    required: ['posting_date', 'description', 'lines'],
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_period_id', type: 'string', format: 'uuid', nullable: true, description: 'Optional: when omitted the open fiscal period that contains posting_date is used (422 when no period contains the date or it is closed/locked)'),
        new OA\Property(property: 'batch_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'entry_number', description: 'Ignored since April 2027: the entry number and the libro giornale number are assigned by the system.', type: 'string', maxLength: 50, nullable: true, deprecated: true),
        new OA\Property(property: 'posting_date', type: 'string', format: 'date', example: '2027-03-31'),
        new OA\Property(property: 'document_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'auto_reverse_on', description: 'Date of the automatic reversal (storno), after the posting date: the entry is reversed on that date by POST /api/v1/general-ledger/scheduled-journals/run or the daily scheduler.', type: 'string', format: 'date', nullable: true, example: '2027-04-01'),
        new OA\Property(property: 'ledger_type', type: 'string', nullable: true, enum: ['statutory', 'ifrs', 'management']),
        new OA\Property(property: 'journal_type', type: 'string', nullable: true, enum: ['standard', 'reversing', 'opening', 'recurring', 'adjusting']),
        new OA\Property(property: 'description', type: 'string', maxLength: 500),
        new OA\Property(property: 'source_document_type', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'source_document_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'status', description: 'posted (default) posts at once, or keeps the journal pending_approval when the tenant requires four-eyes approval for its amount; draft only saves it.', type: 'string', nullable: true, enum: ['draft', 'posted']),
        new OA\Property(property: 'lines', type: 'array', minItems: 2, items: new OA\Items(type: 'object', required: ['account_id'], properties: [new OA\Property(property: 'account_id', type: 'string', format: 'uuid'), new OA\Property(property: 'debit_amount', type: 'number', minimum: 0, description: 'Required without `lines.*.credit_amount`.'), new OA\Property(property: 'credit_amount', type: 'number', minimum: 0, description: 'Required without `lines.*.debit_amount`.'), new OA\Property(property: 'description', type: 'string', maxLength: 255, nullable: true), new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'profit_center_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'partner_type', type: 'string', nullable: true, enum: ['customer', 'supplier', 'employee']), new OA\Property(property: 'partner_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerRunReconciliationRequest',
    description: 'Request payload validated by RunReconciliationRequest',
    required: ['reconciliation_type', 'fiscal_period_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'reconciliation_type', type: 'string', enum: ['subledger_ar', 'subledger_ap', 'gr_ir', 'inventory', 'project']),
        new OA\Property(property: 'fiscal_period_id', type: 'string', format: 'uuid'),
    ]
)]
final class GeneralLedgerRequestSchemas {}
