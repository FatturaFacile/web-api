<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of budgets, schemes and yearly statements. Amounts are decimal strings.
 */
#[OA\Tag(name: 'General Ledger Budgets', description: 'Company budgets by account and cost centre, not to be confused with project budgets')]
#[OA\Schema(
    schema: 'GeneralLedgerBudgetResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'fiscal_year_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved', 'superseded']),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreBudgetRequest',
    required: ['fiscal_year_id', 'name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_year_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', maxLength: 190),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerSetBudgetLinesRequest',
    required: ['lines'],
    type: 'object',
    properties: [new OA\Property(property: 'lines', type: 'array', items: new OA\Items(
        required: ['account_id', 'period_number', 'amount'],
        properties: [
            new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'period_number', type: 'integer', minimum: 1, maximum: 12),
            new OA\Property(property: 'amount', type: 'string', description: 'Natural sign of the account'),
        ],
        type: 'object'
    ))]
)]
#[OA\Schema(
    schema: 'GeneralLedgerBudgetVariance',
    type: 'object',
    properties: [
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'totals', type: 'object', properties: [new OA\Property(property: 'budget', type: 'string'), new OA\Property(property: 'actual', type: 'string'), new OA\Property(property: 'variance', type: 'string')]),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerRollingForecast',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of_period', type: 'integer'),
        new OA\Property(property: 'method', type: 'string', enum: ['budget', 'run_rate']),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'totals', type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStatementSchemeResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'statement_type', type: 'string', enum: ['income_statement', 'balance_sheet']),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerStoreStatementSchemeRequest',
    required: ['name', 'statement_type', 'rows'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 190),
        new OA\Property(property: 'statement_type', type: 'string', enum: ['income_statement', 'balance_sheet']),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(
            required: ['code', 'label', 'row_type'],
            properties: [
                new OA\Property(property: 'code', type: 'string', maxLength: 40),
                new OA\Property(property: 'label', type: 'string', maxLength: 255),
                new OA\Property(property: 'row_type', type: 'string', enum: ['heading', 'accounts', 'subtotal']),
                new OA\Property(property: 'sign', type: 'integer', enum: [1, -1], nullable: true),
                new OA\Property(property: 'account_prefixes', type: 'array', items: new OA\Items(type: 'string'), description: 'Required on an accounts row'),
                new OA\Property(property: 'formula', type: 'array', items: new OA\Items(type: 'object'), description: 'Required on a subtotal: rows above it, each with a sign'),
            ],
            type: 'object'
        )),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerComparativeStatements',
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_year', type: 'string'),
        new OA\Property(property: 'previous_fiscal_year', type: 'string', nullable: true),
        new OA\Property(property: 'income_statement', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'balance_sheet', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'result', type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerReclassifiedStatement',
    type: 'object',
    properties: [
        new OA\Property(property: 'scheme', type: 'string'),
        new OA\Property(property: 'statement_type', type: 'string'),
        new OA\Property(property: 'fiscal_year', type: 'string'),
        new OA\Property(property: 'previous_fiscal_year', type: 'string', nullable: true),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'unmapped_accounts', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerCashFlowStatement',
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_year', type: 'string'),
        new OA\Property(property: 'net_income', type: 'string'),
        new OA\Property(property: 'non_cash_expenses', type: 'string'),
        new OA\Property(property: 'working_capital_changes', type: 'object'),
        new OA\Property(property: 'operating_cash_flow', type: 'string'),
        new OA\Property(property: 'investing_cash_flow', type: 'string'),
        new OA\Property(property: 'financing_cash_flow', type: 'string'),
        new OA\Property(property: 'net_cash_flow', type: 'string'),
        new OA\Property(property: 'change_in_cash', type: 'string'),
        new OA\Property(property: 'is_reconciled', type: 'boolean'),
        new OA\Property(property: 'assumed_classes', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'GeneralLedgerFinancialRatios',
    type: 'object',
    properties: [
        new OA\Property(property: 'fiscal_year', type: 'string'),
        new OA\Property(property: 'liquidity', type: 'object'),
        new OA\Property(property: 'profitability', type: 'object'),
        new OA\Property(property: 'solvency', type: 'object'),
        new OA\Property(property: 'assumed_classes', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
final class LedgerAnalysisSchemas {}
