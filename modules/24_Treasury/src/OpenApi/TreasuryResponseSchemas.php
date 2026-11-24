<?php

declare(strict_types=1);

namespace Modules\Treasury\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'TreasuryBankAccountResource',
    description: 'JSON representation produced by BankAccountResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'company_id', type: 'string'),
        new OA\Property(property: 'bank_name'),
        new OA\Property(property: 'branch_name'),
        new OA\Property(property: 'iban'),
        new OA\Property(property: 'bic_swift'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'account_type'),
        new OA\Property(property: 'current_balance', type: 'number'),
        new OA\Property(property: 'available_balance', type: 'number'),
        new OA\Property(property: 'overdraft_limit', type: 'number'),
        new OA\Property(property: 'is_default', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_pool_header', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryPaymentBatchResource',
    description: 'JSON representation produced by PaymentBatchResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'company_id', type: 'string'),
        new OA\Property(property: 'bank_account_id', type: 'string'),
        new OA\Property(property: 'batch_reference'),
        new OA\Property(property: 'payment_method'),
        new OA\Property(property: 'execution_date', type: 'string', format: 'date'),
        new OA\Property(property: 'total_amount', type: 'number'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'item_count', type: 'integer'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryPaymentLineResource')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryPaymentLineResource',
    description: 'JSON representation produced by PaymentLineResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payment_batch_id', type: 'string'),
        new OA\Property(property: 'line_number', type: 'integer'),
        new OA\Property(property: 'recipient_type'),
        new OA\Property(property: 'recipient_name'),
        new OA\Property(property: 'recipient_iban'),
        new OA\Property(property: 'recipient_bic'),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'remittance_reference'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'execution_date', type: 'string', format: 'date'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryBankReconciliationResource',
    description: 'JSON representation produced by BankReconciliationResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bank_account_id', type: 'string'),
        new OA\Property(property: 'reconciliation_number'),
        new OA\Property(property: 'period_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'bank_statement_balance', type: 'number'),
        new OA\Property(property: 'gl_book_balance', type: 'number'),
        new OA\Property(property: 'difference_amount', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'approved_by'),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryBankStatementResource',
    description: 'JSON representation produced by BankStatementResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bank_account_id', type: 'string'),
        new OA\Property(property: 'statement_number'),
        new OA\Property(property: 'statement_date', type: 'string', format: 'date'),
        new OA\Property(property: 'format'),
        new OA\Property(property: 'opening_balance', type: 'number'),
        new OA\Property(property: 'closing_balance', type: 'number'),
        new OA\Property(property: 'total_debits', type: 'number'),
        new OA\Property(property: 'total_credits', type: 'number'),
        new OA\Property(property: 'lines_count', type: 'integer'),
        new OA\Property(property: 'reconciliation_status'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryCashForecastResource',
    description: 'JSON representation produced by CashForecastResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'forecast_number'),
        new OA\Property(property: 'calculation_date', type: 'string', format: 'date'),
        new OA\Property(property: 'horizon_days', type: 'integer'),
        new OA\Property(property: 'opening_cash_balance', type: 'number'),
        new OA\Property(property: 'projected_inflows', type: 'number'),
        new OA\Property(property: 'projected_outflows', type: 'number'),
        new OA\Property(property: 'closing_cash_balance', type: 'number'),
        new OA\Property(property: 'min_liquidity_threshold', type: 'number'),
        new OA\Property(property: 'deficit_detected', type: 'boolean'),
        new OA\Property(property: 'deficit_date', type: 'string', format: 'date'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryReceivableCollectionResource',
    description: 'JSON representation produced by ReceivableCollectionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'customer_id', type: 'string'),
        new OA\Property(property: 'invoice_id', type: 'string'),
        new OA\Property(property: 'expected_amount', type: 'number'),
        new OA\Property(property: 'collected_amount', type: 'number'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'collection_date', type: 'string', format: 'date'),
        new OA\Property(property: 'payment_method'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'aging_bucket'),
        new OA\Property(property: 'dunning_level', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryWorkingCapitalMetricModel',
    description: 'Attributes of the Working Capital Metric record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'metric_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'accounts_receivable_total', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'accounts_payable_total', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'inventory_valuation_total', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'trailing_12m_revenue', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'trailing_12m_cogs', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'dso_days', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'dpo_days', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'dio_days', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'cash_conversion_cycle_days', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'current_ratio', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'quick_ratio', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryIntercompanyFundingModel',
    description: 'Attributes of the Intercompany Funding record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'transfer_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'transfer_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'intercompany_interest_rate', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryFxRevaluationModel',
    description: 'Attributes of the Fx Revaluation record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'period_end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'foreign_amount', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'book_value_base', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'revalued_market_base', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'unrealized_gain_loss', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
final class TreasuryResponseSchemas {}
