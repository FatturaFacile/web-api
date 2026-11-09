<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Schemas, shared parameters and tags of the ledger controlling endpoints: settings, recurring journals, accruals and
 * deferrals, period close checklist, FX revaluation of open items, dimensions and reports. Amounts are decimal strings
 * with 4 decimals unless stated otherwise.
 */
#[OA\Tag(name: 'General Ledger Settings', description: 'Default posting accounts by role and four-eyes approval policy of manual journals')]
#[OA\Tag(name: 'General Ledger Recurring Journals', description: 'Recurring journal templates and the scheduled ledger work (recurring runs, automatic reversals)')]
#[OA\Tag(name: 'General Ledger Accruals', description: 'Accruals and deferrals (ratei e risconti) computed by days, reversed automatically the day after the cut-off')]
#[OA\Tag(name: 'General Ledger FX Revaluation', description: 'Period-end revaluation of open foreign-currency receivables and payables')]
#[OA\Tag(name: 'General Ledger Dimensions', description: 'Cost centres and profit centres of the journal lines')]
#[OA\Tag(name: 'General Ledger Reports', description: 'Trial balance, account ledger, balance sheet, income statement and dimension analysis from posted entries; audit trail export')]
#[OA\Schema(
    schema: 'GeneralLedgerSettingsResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'posting_accounts', description: 'One entry per role: sales_revenue, purchase_expense, output_vat, input_vat, receivables, payables, retained_earnings, fx_unrealised_gain, fx_unrealised_loss, rounding', type: 'object', additionalProperties: new OA\AdditionalProperties(properties: [
            new OA\Property(property: 'account_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'account_code', type: 'string', nullable: true),
            new OA\Property(property: 'account_name', type: 'string', nullable: true),
            new OA\Property(property: 'source', type: 'string', enum: ['settings', 'preferences'], nullable: true),
        ], type: 'object')),
        new OA\Property(property: 'manual_journal_approval_required', type: 'boolean'),
        new OA\Property(property: 'manual_journal_approval_threshold', type: 'string', nullable: true, example: '10000.0000'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerUpdateSettingsRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'posting_accounts', description: 'Role => account id of the tenant (null goes back to the preference code)', type: 'object', example: ['retained_earnings' => '01960000-0000-7000-8000-000000000001', 'fx_unrealised_gain' => null], additionalProperties: new OA\AdditionalProperties(type: 'string', format: 'uuid', nullable: true)),
        new OA\Property(property: 'manual_journal_approval_required', type: 'boolean', example: true),
        new OA\Property(property: 'manual_journal_approval_threshold', description: 'Total from which approval is needed; null = every manual journal', type: 'number', minimum: 0, nullable: true, example: 5000),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreRecurringJournalRequest',
    required: ['name', 'description', 'frequency', 'start_date', 'lines'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 190, example: 'Office rent'),
        new OA\Property(property: 'description', type: 'string', maxLength: 450, example: 'Monthly office rent'),
        new OA\Property(property: 'frequency', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'yearly']),
        new OA\Property(property: 'start_date', description: 'First due date', type: 'string', format: 'date', example: '2027-01-31'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'reverse_next_day', type: 'boolean', default: false),
        new OA\Property(property: 'lines', type: 'array', minItems: 2, items: new OA\Items(required: ['account_id'], properties: [
            new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'debit_amount', type: 'number', minimum: 0, example: 1200),
            new OA\Property(property: 'credit_amount', type: 'number', minimum: 0, example: 0),
            new OA\Property(property: 'description', type: 'string', maxLength: 255, nullable: true),
            new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'profit_center_id', type: 'string', format: 'uuid', nullable: true),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerRecurringJournalResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'frequency', type: 'string', enum: ['monthly', 'quarterly', 'semiannual', 'yearly']),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'next_run_date', type: 'string', format: 'date'),
        new OA\Property(property: 'last_run_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'reverse_next_day', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'ended']),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'runs', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'run_date', type: 'string', format: 'date'), new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid')], type: 'object')),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerScheduledRunResult',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of', type: 'string', format: 'date'),
        new OA\Property(property: 'recurring', properties: [
            new OA\Property(property: 'posted', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(property: 'failed', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'recurring_journal_id', type: 'string', format: 'uuid'), new OA\Property(property: 'message', type: 'string')], type: 'object')),
        ], type: 'object'),
        new OA\Property(property: 'reversals', properties: [
            new OA\Property(property: 'reversed', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(property: 'failed', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid'), new OA\Property(property: 'message', type: 'string')], type: 'object')),
        ], type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreAccrualRequest',
    required: ['accrual_type', 'description', 'total_amount', 'coverage_start', 'coverage_end', 'cutoff_date', 'income_statement_account_id', 'balance_sheet_account_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'accrual_type', description: 'accrued_revenue = rateo attivo, accrued_expense = rateo passivo, prepaid_expense = risconto attivo, deferred_revenue = risconto passivo', type: 'string', enum: ['accrued_revenue', 'accrued_expense', 'prepaid_expense', 'deferred_revenue']),
        new OA\Property(property: 'description', type: 'string', maxLength: 255, example: 'Insurance premium 01/10/2027-30/09/2028'),
        new OA\Property(property: 'total_amount', description: 'Amount of the whole coverage', type: 'number', example: 1200),
        new OA\Property(property: 'coverage_start', type: 'string', format: 'date', example: '2027-10-01'),
        new OA\Property(property: 'coverage_end', type: 'string', format: 'date', example: '2028-09-30'),
        new OA\Property(property: 'cutoff_date', type: 'string', format: 'date', example: '2027-12-31'),
        new OA\Property(property: 'income_statement_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'balance_sheet_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerAccrualResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'accrual_type', type: 'string', enum: ['accrued_revenue', 'accrued_expense', 'prepaid_expense', 'deferred_revenue']),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'total_amount', type: 'string', example: '1200.0000'),
        new OA\Property(property: 'coverage_start', type: 'string', format: 'date'),
        new OA\Property(property: 'coverage_end', type: 'string', format: 'date'),
        new OA\Property(property: 'cutoff_date', type: 'string', format: 'date'),
        new OA\Property(property: 'coverage_days', type: 'integer', example: 366),
        new OA\Property(property: 'accrued_days', type: 'integer', example: 274),
        new OA\Property(property: 'amount', description: 'Share posted at the cut-off', type: 'string', example: '898.3600'),
        new OA\Property(property: 'income_statement_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'balance_sheet_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'journal_status', type: 'string', enum: ['pending_approval', 'posted', 'rejected', 'reversed'], nullable: true),
        new OA\Property(property: 'reversal_date', type: 'string', format: 'date'),
        new OA\Property(property: 'reversal_journal_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerPeriodCloseTask',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fiscal_period_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'is_blocking', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'done', 'waived']),
        new OA\Property(property: 'created_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'completed_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completion_note', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerPeriodCloseChecklist',
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_period_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'period_name', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'can_close', type: 'boolean'),
        new OA\Property(property: 'checks', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'code', type: 'string', enum: ['no_drafts', 'no_pending_approvals', 'recurring_journals_posted', 'automatic_reversals_posted', 'entries_balanced', 'subledgers_reconciled']),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'blocking', type: 'boolean'),
            new OA\Property(property: 'passed', type: 'boolean'),
            new OA\Property(property: 'count', type: 'integer'),
        ], type: 'object')),
        new OA\Property(property: 'tasks', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerPeriodCloseTask')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerFxRevaluationResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'revaluation_date', type: 'string', format: 'date'),
        new OA\Property(property: 'side', type: 'string', enum: ['receivable', 'payable']),
        new OA\Property(property: 'currency', type: 'string', example: 'USD'),
        new OA\Property(property: 'account_id', description: 'Control account revalued', type: 'string', format: 'uuid'),
        new OA\Property(property: 'closing_rate', description: 'Foreign units per accounting unit', type: 'string', example: '1.10000000'),
        new OA\Property(property: 'open_amount', description: 'Open amount in the foreign currency', type: 'string'),
        new OA\Property(property: 'book_value', description: 'At historical rates, accounting currency', type: 'string'),
        new OA\Property(property: 'revalued_value', description: 'At the closing rate, accounting currency', type: 'string'),
        new OA\Property(property: 'difference', type: 'string'),
        new OA\Property(property: 'open_items', type: 'integer'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reversal_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreDimensionRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'code', description: 'Required on create, unique in the tenant', type: 'string', maxLength: 50, example: 'CC-ADMIN'),
        new OA\Property(property: 'name', description: 'Required on create', type: 'string', maxLength: 255, example: 'Administration'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerDimensionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerTrialBalanceReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
        new OA\Property(property: 'compare_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'compare_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'account_code', type: 'string'),
            new OA\Property(property: 'account_name', type: 'string'),
            new OA\Property(property: 'account_type', type: 'string'),
            new OA\Property(property: 'normal_balance', type: 'string'),
            new OA\Property(property: 'opening_balance', description: 'Debit minus credit before the range', type: 'string'),
            new OA\Property(property: 'debit', type: 'string'),
            new OA\Property(property: 'credit', type: 'string'),
            new OA\Property(property: 'closing_balance', description: 'Debit minus credit at the end of the range', type: 'string'),
            new OA\Property(property: 'comparison_debit', type: 'string', nullable: true),
            new OA\Property(property: 'comparison_credit', type: 'string', nullable: true),
            new OA\Property(property: 'drill_down', description: 'Query of the account ledger of the range', type: 'string'),
        ], type: 'object')),
        new OA\Property(property: 'totals', properties: [
            new OA\Property(property: 'opening_balance', type: 'string'),
            new OA\Property(property: 'debit', type: 'string'),
            new OA\Property(property: 'credit', type: 'string'),
            new OA\Property(property: 'closing_balance', type: 'string'),
        ], type: 'object'),
        new OA\Property(property: 'is_balanced', description: 'Debits equal credits in the range and the closing balances sum to zero', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerAccountLedgerReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'account', type: 'object'),
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
        new OA\Property(property: 'opening_balance', type: 'string'),
        new OA\Property(property: 'closing_balance', type: 'string'),
        new OA\Property(property: 'total_debit', type: 'string'),
        new OA\Property(property: 'total_credit', type: 'string'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'entry_number', type: 'string'),
            new OA\Property(property: 'journal_number', type: 'integer', nullable: true),
            new OA\Property(property: 'posting_date', type: 'string', format: 'date'),
            new OA\Property(property: 'journal_type', type: 'string'),
            new OA\Property(property: 'status', type: 'string'),
            new OA\Property(property: 'source_document_type', type: 'string', nullable: true),
            new OA\Property(property: 'source_document_id', type: 'string', nullable: true),
            new OA\Property(property: 'description', type: 'string'),
            new OA\Property(property: 'partner_type', type: 'string', nullable: true),
            new OA\Property(property: 'partner_id', type: 'string', nullable: true),
            new OA\Property(property: 'cost_center_id', type: 'string', nullable: true),
            new OA\Property(property: 'profit_center_id', type: 'string', nullable: true),
            new OA\Property(property: 'project_id', type: 'string', nullable: true),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'debit', type: 'string'),
            new OA\Property(property: 'credit', type: 'string'),
            new OA\Property(property: 'running_balance', type: 'string'),
        ], type: 'object')),
        new OA\Property(property: 'meta', properties: [
            new OA\Property(property: 'current_page', type: 'integer'),
            new OA\Property(property: 'last_page', type: 'integer'),
            new OA\Property(property: 'per_page', type: 'integer'),
            new OA\Property(property: 'total', type: 'integer'),
        ], type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStatementReport',
    description: 'Balance sheet (sections asset, liability, equity; as_of/compare_as_of) or income statement (sections revenue, cogs, expense; from/to/compare_from/compare_to). With a comparison, every account carries comparison_amount and variance, every section comparison_total and variance.',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'compare_as_of', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'sections', type: 'object', additionalProperties: new OA\AdditionalProperties(properties: [
            new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'account_code', type: 'string'),
                new OA\Property(property: 'account_name', type: 'string'),
                new OA\Property(property: 'amount', type: 'string'),
                new OA\Property(property: 'comparison_amount', type: 'string', nullable: true),
                new OA\Property(property: 'variance', type: 'string', nullable: true),
                new OA\Property(property: 'drill_down', type: 'string', nullable: true),
            ], type: 'object')),
            new OA\Property(property: 'total', type: 'string'),
            new OA\Property(property: 'comparison_total', type: 'string', nullable: true),
            new OA\Property(property: 'variance', type: 'string', nullable: true),
        ], type: 'object')),
        new OA\Property(property: 'totals', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'comparison_totals', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'is_balanced', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerDimensionReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'dimension', type: 'string', enum: ['cost_center', 'profit_center', 'project']),
        new OA\Property(property: 'from', type: 'string', format: 'date'),
        new OA\Property(property: 'to', type: 'string', format: 'date'),
        new OA\Property(property: 'groups', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'dimension_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'dimension_code', type: 'string', nullable: true),
            new OA\Property(property: 'dimension_name', type: 'string', nullable: true),
            new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(type: 'object')),
            new OA\Property(property: 'result', description: 'Revenue minus costs of the group', type: 'string'),
        ], type: 'object')),
        new OA\Property(property: 'total', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerAuditTrailRow',
    type: 'object',
    properties: [
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'journal_number', type: 'integer', nullable: true),
        new OA\Property(property: 'entry_number', type: 'string'),
        new OA\Property(property: 'posting_date', type: 'string', format: 'date'),
        new OA\Property(property: 'journal_type', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'source_document_type', type: 'string', nullable: true),
        new OA\Property(property: 'source_document_id', type: 'string', nullable: true),
        new OA\Property(property: 'total_debit', type: 'string'),
        new OA\Property(property: 'total_credit', type: 'string'),
        new OA\Property(property: 'created_by', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'submitted_by', type: 'string', nullable: true),
        new OA\Property(property: 'approved_by', type: 'string', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'rejected_by', type: 'string', nullable: true),
        new OA\Property(property: 'posted_by', type: 'string', nullable: true),
        new OA\Property(property: 'posted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reversed_by', type: 'string', nullable: true),
        new OA\Property(property: 'reversed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reversal_journal_entry_id', type: 'string', nullable: true),
        new OA\Property(property: 'auto_reverse_on', type: 'string', format: 'date', nullable: true),
    ]
)]
final class GeneralLedgerControllingSchemas {}
