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
        new OA\Property(property: 'reporting_class', type: 'string', nullable: true),
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
        new OA\Property(property: 'journal_number', description: 'Progressive libro giornale number per tenant and fiscal year, assigned at posting', type: 'integer', nullable: true),
        new OA\Property(property: 'fiscal_year_id', type: 'string', format: 'uuid', nullable: true),
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
        new OA\Property(property: 'settlement_journal_id', type: 'string', nullable: true),
        new OA\Property(property: 'period_type', type: 'string', enum: ['monthly', 'quarterly', 'semiannual']),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'period', type: 'integer', description: 'Month, quarter or half year number'),
        new OA\Property(property: 'period_from', type: 'string', format: 'date'),
        new OA\Property(property: 'period_to', type: 'string', format: 'date'),
        new OA\Property(property: 'country', type: 'string', enum: ['CH', 'IT']),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'turnover', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'taxable_turnover', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'passive_operations', type: 'string', description: 'Decimal string: net purchases of the period'),
        new OA\Property(property: 'previous_credit', type: 'string', description: 'Decimal string: credit carried over from the previous settlement'),
        new OA\Property(property: 'previous_debit', type: 'string', description: 'Decimal string: debt below the minimum payment carried over'),
        new OA\Property(property: 'interest_amount', type: 'string', description: 'Decimal string: Italian quarterly interest'),
        new OA\Property(property: 'payable_amount', type: 'string', description: 'Decimal string: positive when due, negative when a credit'),
        new OA\Property(property: 'below_minimum_payment', type: 'boolean', description: 'The Italian debt is below EUR 25.82 and moves to the next period'),
        new OA\Property(property: 'rate_breakdown', type: 'array', items: new OA\Items(
            properties: [
                new OA\Property(property: 'rate', type: 'string'),
                new OA\Property(property: 'taxable', type: 'string'),
                new OA\Property(property: 'tax', type: 'string'),
            ],
            type: 'object'
        )),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'details', type: 'string', nullable: true),
        new OA\Property(property: 'posted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'payment_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'payment_journal_id', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerVatTaxSettingResource',
    description: 'JSON representation produced by VatTaxSettingResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'periodicity', type: 'string', enum: ['monthly', 'quarterly', 'semiannual'], nullable: true),
        new OA\Property(property: 'output_vat_account_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'input_vat_account_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'settlement_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'interest_expense_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'declarant_tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'declarant_role_code', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerWithholdingSettlementResource',
    description: 'Monthly withholding settlement',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'total_withheld', type: 'string', description: 'Decimal string: everything withheld, contributions included'),
        new OA\Property(property: 'total_f24', type: 'string', description: 'Decimal string: what is paid with the F24'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['calculated', 'paid']),
        new OA\Property(property: 'payment_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'payment_journal_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerWithholdingF24Data',
    description: 'F24 data of a withholding settlement',
    type: 'object',
    properties: [
        new OA\Property(property: 'section', type: 'string', example: 'erario'),
        new OA\Property(property: 'reference_month', type: 'string', example: '03'),
        new OA\Property(property: 'reference_year', type: 'integer'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'total', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'tax_code', type: 'string'),
            new OA\Property(property: 'reference_month', type: 'string'),
            new OA\Property(property: 'reference_year', type: 'integer'),
            new OA\Property(property: 'amount', type: 'string', description: 'Decimal string'),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerEnasarcoParameter',
    description: 'Enasarco rate and ceiling of a year and agent type',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true, description: 'Null on a generic, read-only row'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'agent_type', type: 'string', enum: ['single_mandate', 'multi_mandate']),
        new OA\Property(property: 'rate_percent', type: 'string'),
        new OA\Property(property: 'agent_share_percent', type: 'string'),
        new OA\Property(property: 'annual_ceiling', type: 'string'),
        new OA\Property(property: 'source', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerVatF24Data',
    description: 'F24 data of an Italian VAT settlement',
    type: 'object',
    properties: [
        new OA\Property(property: 'section', type: 'string', example: 'erario'),
        new OA\Property(property: 'tax_code', type: 'string', nullable: true, example: '6031'),
        new OA\Property(property: 'reference_year', type: 'integer'),
        new OA\Property(property: 'amount', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'payment_due', type: 'boolean'),
        new OA\Property(property: 'carried_forward', type: 'string', description: 'Decimal string: credit or small debt moved to the next period'),
        new OA\Property(property: 'summary', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerVatRegisterLine',
    description: 'One VAT rate of one document of a VAT register',
    type: 'object',
    properties: [
        new OA\Property(property: 'protocol_number', type: 'integer', nullable: true, description: 'Assigned when the settlement of the period is posted'),
        new OA\Property(property: 'posting_date', type: 'string', format: 'date'),
        new OA\Property(property: 'document_date', type: 'string', format: 'date'),
        new OA\Property(property: 'document_number', type: 'string'),
        new OA\Property(property: 'document_type', type: 'string', nullable: true),
        new OA\Property(property: 'partner_name', type: 'string', nullable: true),
        new OA\Property(property: 'taxable_amount', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'vat_rate', type: 'string'),
        new OA\Property(property: 'vat_amount', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'vat_treatment', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerEnasarcoPrincipalStatementRequest',
    description: 'Quarter to compute and agent type of the agents',
    required: ['year', 'quarter', 'agent_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'quarter', type: 'integer', maximum: 4, minimum: 1),
        new OA\Property(property: 'agent_type', description: 'Agent type of the agents not listed in agent_types.', type: 'string', enum: ['single_mandate', 'multi_mandate']),
        new OA\Property(property: 'agent_types', description: 'Agent type by agent contact id.', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['single_mandate', 'multi_mandate'])),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerEnasarcoPrincipalStatementLine',
    description: 'Line of an agent in the quarterly Enasarco statement of the tenant as a principal',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'quarter', type: 'integer'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'agent_type', type: 'string', enum: ['single_mandate', 'multi_mandate']),
        new OA\Property(property: 'documents', type: 'integer'),
        new OA\Property(property: 'commissions', type: 'string', description: 'Taxable commissions of the quarter'),
        new OA\Property(property: 'subject_before', type: 'string', description: 'Commissions of the year before the quarter counted against the ceiling'),
        new OA\Property(property: 'subject_base', type: 'string', description: 'Commissions of the quarter below the annual ceiling'),
        new OA\Property(property: 'annual_ceiling', type: 'string'),
        new OA\Property(property: 'rate_percent', type: 'string'),
        new OA\Property(property: 'agent_share_percent', type: 'string'),
        new OA\Property(property: 'agent_withheld', type: 'string', description: 'Agent share withheld on the received invoices'),
        new OA\Property(property: 'principal_amount', type: 'string', description: 'Principal share, posted to the ledger'),
        new OA\Property(property: 'total_due', type: 'string'),
        new OA\Property(property: 'journal_entry_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerEnasarcoPrincipalStatementResponse',
    description: 'Quarterly Enasarco statement of the tenant as a principal',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'object', properties: [
            new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerEnasarcoPrincipalStatementLine')),
            new OA\Property(property: 'total_agent_withheld', type: 'string'),
            new OA\Property(property: 'total_principal_amount', type: 'string'),
            new OA\Property(property: 'total_due', type: 'string'),
        ]),
    ]
)]
final class GeneralLedgerResponseSchemas {}
