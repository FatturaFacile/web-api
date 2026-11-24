<?php

declare(strict_types=1);

namespace Modules\Treasury\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the treasury endpoints as they leave the controllers, verified by TreasuryResponseShapesTest.
 *
 * Money of the services and of the Eloquent models (balances, riba receipts, FX positions) is a decimal string;
 * the amounts of the legacy resources (collections, cash forecasts, reconciliations, statements) and of the intraday
 * position, the liquidity buffer and the collections aging are JSON numbers: they are documented as they are.
 * The endpoints that answer `{success, message, data}` use the standard envelope, the others a bare resource.
 */
#[OA\Schema(
    schema: 'TreasuryPaginatorLinks',
    description: 'Links of a Laravel paginator answered by the resource collections that are not wrapped in the standard envelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'first', type: 'string', nullable: true),
        new OA\Property(property: 'last', type: 'string', nullable: true),
        new OA\Property(property: 'prev', type: 'string', nullable: true),
        new OA\Property(property: 'next', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryPaginatorMeta',
    description: 'Paginator metadata of the bare resource collections',
    type: 'object',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'from', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'last_page', type: 'integer', example: 1),
        new OA\Property(property: 'links', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'url', type: 'string', nullable: true),
            new OA\Property(property: 'label', type: 'string'),
            new OA\Property(property: 'page', type: 'integer', nullable: true),
            new OA\Property(property: 'active', type: 'boolean'),
        ])),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'per_page', type: 'integer', example: 25),
        new OA\Property(property: 'to', type: 'integer', nullable: true, example: 1),
        new OA\Property(property: 'total', type: 'integer', example: 1),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryBankMovementEnvelope',
    description: 'A treasury bank movement (transfer, bank fee, interest). Answered with 201 when created and with 200 and the same body when the idempotency key was already recorded.',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryBankMovementResource'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryCashMovementEnvelope',
    description: 'A cash register movement. Answered with 201 when created and with 200 and the same body when the idempotency key was already recorded.',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryCashMovementResource'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryBankBalanceSnapshot',
    description: 'Balance of a bank account at a date: from the ledger (book), from the last statement and from the stored snapshot',
    type: 'object',
    properties: [
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bank_name', type: 'string'),
        new OA\Property(property: 'iban', type: 'string'),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'book_balance', type: 'string', example: '9895.00'),
        new OA\Property(property: 'statement_balance', type: 'string', nullable: true, example: '10100.00'),
        new OA\Property(property: 'statement_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'snapshot_balance', type: 'string', nullable: true),
        new OA\Property(property: 'current_balance', type: 'string', example: '9895.00', description: 'Balance kept on the bank account record'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryBankBalancesEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Bank balances'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryBankBalanceSnapshot')),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryBankBalanceHistory',
    type: 'object',
    properties: [
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'from_date', type: 'string', format: 'date'),
        new OA\Property(property: 'to_date', type: 'string', format: 'date'),
        new OA\Property(property: 'opening_balance', type: 'string', example: '0.00', description: 'Ledger balance before from_date'),
        new OA\Property(property: 'days', type: 'array', description: 'Only the days with movements', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'debit', type: 'string', example: '0.00'),
            new OA\Property(property: 'credit', type: 'string', example: '105.00'),
            new OA\Property(property: 'balance', type: 'string', example: '-105.00', description: 'Running balance at the end of the day'),
        ])),
        new OA\Property(property: 'at_end', ref: '#/components/schemas/TreasuryBankBalanceSnapshot'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryBankBalanceHistoryEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Bank account balance history'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryBankBalanceHistory'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryCashPosition',
    description: 'Cash position of the company: active bank accounts and cash registers, totals by currency',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of', type: 'string', format: 'date-time'),
        new OA\Property(property: 'bank_accounts', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'bank_name', type: 'string'),
            new OA\Property(property: 'iban', type: 'string'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'current_balance', type: 'string', example: '9895.00'),
            new OA\Property(property: 'available_balance', type: 'string', example: '9895.00'),
            new OA\Property(property: 'overdraft_limit', type: 'string', example: '0.00'),
        ])),
        new OA\Property(property: 'cash_registers', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'code', type: 'string'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'current_balance', type: 'string', example: '150.00'),
        ])),
        new OA\Property(property: 'totals', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'bank', type: 'string', example: '19995.00'),
            new OA\Property(property: 'cash', type: 'string', example: '0.00'),
            new OA\Property(property: 'total', type: 'string', example: '19995.00'),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryCashPositionEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Cash position'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryCashPosition'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryKpis',
    description: 'Treasury KPIs of a period: flows, open items, liquidity and cycle days. The ratios are null when their base is zero or unknown.',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of', type: 'string', format: 'date'),
        new OA\Property(property: 'period_from', type: 'string', format: 'date'),
        new OA\Property(property: 'period_days', type: 'integer', example: 365),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'sales', type: 'string', example: '0.00'),
        new OA\Property(property: 'purchases', type: 'string', example: '0.00'),
        new OA\Property(property: 'open_receivables', type: 'string', example: '0.00'),
        new OA\Property(property: 'overdue_receivables', type: 'string', example: '0.00'),
        new OA\Property(property: 'open_payables', type: 'string', example: '0.00'),
        new OA\Property(property: 'overdue_payables', type: 'string', example: '0.00'),
        new OA\Property(property: 'cash', type: 'string', example: '19995.00'),
        new OA\Property(property: 'net_liquidity', type: 'string', example: '19995.00'),
        new OA\Property(property: 'quick_ratio', type: 'string', nullable: true),
        new OA\Property(property: 'dso_days', type: 'string', nullable: true),
        new OA\Property(property: 'dpo_days', type: 'string', nullable: true),
        new OA\Property(property: 'dio_days', type: 'string', nullable: true),
        new OA\Property(property: 'cash_conversion_cycle_days', type: 'string', nullable: true),
        new OA\Property(property: 'includes_inventory', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryKpisEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Treasury KPIs'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryKpis'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryLiquidityScenarioProjection',
    description: 'Daily projection of one forecast scenario. Only the days with flows are listed.',
    type: 'object',
    properties: [
        new OA\Property(property: 'scenario', type: 'string', example: 'BASELINE', description: 'Code of the scenario (BASELINE when none was requested)'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'assumptions', type: 'object', properties: [
            new OA\Property(property: 'collection_delay_days', type: 'integer', example: 0),
            new OA\Property(property: 'disbursement_acceleration_days', type: 'integer', example: 0),
            new OA\Property(property: 'sales_growth_factor', type: 'string', example: '1'),
        ]),
        new OA\Property(property: 'total_inflows', type: 'string', example: '0.00'),
        new OA\Property(property: 'total_outflows', type: 'string', example: '0.00'),
        new OA\Property(property: 'closing_balance', type: 'string', example: '19995.00'),
        new OA\Property(property: 'lowest_balance', type: 'object', properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'balance', type: 'string', example: '19995.00'),
        ]),
        new OA\Property(property: 'first_day_below_minimum', type: 'string', format: 'date', nullable: true, description: 'First day the balance is under minimum_balance; null when no minimum was given or it is never crossed'),
        new OA\Property(property: 'net_by_source', type: 'object', description: 'Net flow by source: receivable, payable, recurring (only the sources with flows)', additionalProperties: new OA\AdditionalProperties(type: 'string', example: '1220.00')),
        new OA\Property(property: 'days', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'inflows', type: 'string', example: '1220.00'),
            new OA\Property(property: 'outflows', type: 'string', example: '0.00'),
            new OA\Property(property: 'balance', type: 'string', example: '21215.00', description: 'Running balance at the end of the day'),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryLiquidityForecast',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of', type: 'string', format: 'date'),
        new OA\Property(property: 'until', type: 'string', format: 'date'),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'opening_cash', type: 'string', example: '19995.00', description: 'Bank accounts and cash registers of the currency'),
        new OA\Property(property: 'minimum_balance', type: 'string', nullable: true),
        new OA\Property(property: 'scenarios', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryLiquidityScenarioProjection')),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryLiquidityForecastEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Liquidity forecast'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryLiquidityForecast'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryIntradayPosition',
    description: 'Bare object (no envelope). Balances are JSON numbers in the base currency of the company.',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of', type: 'string', format: 'date-time'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'company_id', type: 'string', nullable: true),
        new OA\Property(property: 'base_currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'total_ledger_balance_base', type: 'number', example: 19995),
        new OA\Property(property: 'total_available_balance_base', type: 'number', example: 19995),
        new OA\Property(property: 'total_overdraft_facility_base', type: 'number', example: 0),
        new OA\Property(property: 'positions_by_currency', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'ledger_balance', type: 'number'),
            new OA\Property(property: 'available_balance', type: 'number'),
            new OA\Property(property: 'overdraft_limit', type: 'number'),
            new OA\Property(property: 'account_count', type: 'integer'),
            new OA\Property(property: 'accounts', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'bank_name', type: 'string'),
                new OA\Property(property: 'iban', type: 'string'),
                new OA\Property(property: 'account_type', type: 'string'),
                new OA\Property(property: 'current_balance', type: 'number'),
                new OA\Property(property: 'available_balance', type: 'number'),
                new OA\Property(property: 'overdraft_limit', type: 'number'),
            ])),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryFxPosition',
    description: 'Foreign-currency position of a currency at a date, valued with the latest published rate (base per unit of the currency as published by the exchange rates)',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'company_id', type: 'string', nullable: true),
        new OA\Property(property: 'currency', type: 'string', example: 'USD'),
        new OA\Property(property: 'as_of_date', type: 'string', format: 'date-time', example: '2026-03-01T00:00:00.000000Z'),
        new OA\Property(property: 'total_cash_holdings', type: 'string', example: '500.00'),
        new OA\Property(property: 'open_ar_exposure', type: 'string', example: '0.00'),
        new OA\Property(property: 'open_ap_exposure', type: 'string', example: '0.00'),
        new OA\Property(property: 'net_exposure', type: 'string', example: '500.00'),
        new OA\Property(property: 'base_currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'latest_spot_rate', type: 'string', example: '1.250000'),
        new OA\Property(property: 'var_95_daily', type: 'string', nullable: true, description: 'Null when the history of rates is too short'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryFxExposure',
    description: 'Bare object keyed by foreign currency code; currencies without a published rate are left out. When there is no foreign-currency position the answer is an empty JSON array `[]`.',
    type: 'object',
    additionalProperties: new OA\AdditionalProperties(ref: '#/components/schemas/TreasuryFxPosition')
)]
#[OA\Schema(
    schema: 'TreasuryCollectionsAging',
    description: 'Open receivables of the collections by age bucket (days past due). Bare object, amounts are JSON numbers.',
    type: 'object',
    properties: [
        new OA\Property(property: 'as_of_date', type: 'string', format: 'date'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'company_id', type: 'string', nullable: true),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'total_open_receivables', type: 'number', example: 300),
        new OA\Property(property: 'buckets', type: 'object', properties: [
            new OA\Property(property: 'current', type: 'number'),
            new OA\Property(property: '1_30', type: 'number'),
            new OA\Property(property: '31_60', type: 'number'),
            new OA\Property(property: '61_90', type: 'number'),
            new OA\Property(property: 'over_90', type: 'number'),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryCashForecastDeficitEvaluation',
    description: 'Bare object, amounts are JSON numbers',
    type: 'object',
    properties: [
        new OA\Property(property: 'forecast_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'opening_balance', type: 'number', example: 19995),
        new OA\Property(property: 'inflows', type: 'number', example: 0),
        new OA\Property(property: 'outflows', type: 'number', example: 0),
        new OA\Property(property: 'closing_balance', type: 'number', example: 19995),
        new OA\Property(property: 'deficit_detected', type: 'boolean'),
        new OA\Property(property: 'deficit_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaReceipt',
    description: 'Ri.Ba. receipt as stored (Eloquent attributes). Dates are ISO date-times at midnight UTC. `invoice` is present only on the answers of paid and unpaid.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'riba_presentation_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payment_batch_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payment_line_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payment_id', type: 'string', format: 'uuid', nullable: true, description: 'Set once the receipt is paid'),
        new OA\Property(property: 'receipt_number', type: 'integer', description: 'Progressive number inside the batch'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date-time', example: '2026-03-31T00:00:00.000000Z'),
        new OA\Property(property: 'amount', type: 'string', example: '122.00'),
        new OA\Property(property: 'debtor_name', type: 'string', nullable: true),
        new OA\Property(property: 'debtor_tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'debtor_address', type: 'string', nullable: true),
        new OA\Property(property: 'debtor_postal_code', type: 'string', nullable: true),
        new OA\Property(property: 'debtor_city', type: 'string', nullable: true),
        new OA\Property(property: 'debtor_abi', type: 'string', nullable: true),
        new OA\Property(property: 'debtor_cab', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'presented', 'paid', 'unpaid'], description: 'pending until the file is exported, presented once exported'),
        new OA\Property(property: 'unpaid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'unpaid_reason', type: 'string', nullable: true),
        new OA\Property(property: 'unpaid_fee', type: 'string', nullable: true, example: '7.50'),
        new OA\Property(property: 'invoice', type: 'object', description: 'The invoice of the receipt, with all its attributes (see the invoice resource)'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaPresentation',
    description: 'Ri.Ba. presentation (the flat file of a batch) with its receipts. On creation only the attributes given at creation are present (file_name, file_sha256 and exported_at appear after the export); the flat file content is never in the body, download it from the file endpoint.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payment_batch_id', type: 'string', format: 'uuid', description: 'The payment batch that carries the approval workflow'),
        new OA\Property(property: 'sia_code', type: 'string', example: 'A1B2C'),
        new OA\Property(property: 'file_name', type: 'string', nullable: true, example: 'RIBA-1.txt'),
        new OA\Property(property: 'file_sha256', type: 'string', nullable: true),
        new OA\Property(property: 'exported_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'receipts', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryRibaReceipt')),
        new OA\Property(property: 'payment_batch', type: 'object', description: 'Only on GET of the batch: the payment batch with all its attributes (id, status, format cbi_riba, total_amount, currency, execution_date, bank_account_id, batch_reference, payment_method riba, item_count, created_by, exported_at, ...)'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaPresentationEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryRibaPresentation'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaReceiptEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryRibaReceipt'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaBatchSummary',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', description: 'Payment batch id'),
        new OA\Property(property: 'batch_reference', type: 'string', nullable: true),
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', example: 'approved'),
        new OA\Property(property: 'execution_date', type: 'string', format: 'date'),
        new OA\Property(property: 'total_amount', type: 'string', example: '122.00'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'item_count', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaBatchListEnvelope',
    description: 'A page of the Ri.Ba. batches, newest first (grid contract: sort_by, filter[col][], per_page)',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Ri.Ba. batches'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryRibaBatchSummary')),
        new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer')]),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaPortfolio',
    type: 'object',
    properties: [
        new OA\Property(property: 'by_status', type: 'object', description: 'Count and amount of the receipts by status (only the statuses present)', additionalProperties: new OA\AdditionalProperties(type: 'object', properties: [
            new OA\Property(property: 'count', type: 'integer'),
            new OA\Property(property: 'amount', type: 'string', example: '122.00'),
        ])),
        new OA\Property(property: 'open_receipts', type: 'array', description: 'Receipts pending or presented, by due date', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'payment_batch_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'receipt_number', type: 'integer'),
            new OA\Property(property: 'debtor_name', type: 'string', nullable: true),
            new OA\Property(property: 'due_date', type: 'string', format: 'date'),
            new OA\Property(property: 'amount', type: 'string', example: '122.00'),
            new OA\Property(property: 'status', type: 'string', enum: ['pending', 'presented']),
            new OA\Property(property: 'overdue', type: 'boolean', description: 'Presented and past its due date'),
        ])),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRibaPortfolioEnvelope',
    type: 'object',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Ri.Ba. portfolio'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TreasuryRibaPortfolio'),
    ]
)]
final class TreasuryShapeSchemas {}
