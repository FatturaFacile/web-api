<?php

declare(strict_types=1);

namespace Modules\Treasury\OpenApi;

final class TreasuryOpenApiSchemas
{
    /**
     * @return array<string, mixed>
     */
    public static function schemas(): array
    {
        return [
            'BankAccount' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'tenant_id' => ['type' => 'string', 'format' => 'uuid'],
                    'company_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'bank_name' => ['type' => 'string'],
                    'branch_name' => ['type' => 'string', 'nullable' => true],
                    'iban' => ['type' => 'string'],
                    'bic_swift' => ['type' => 'string', 'nullable' => true],
                    'currency' => ['type' => 'string', 'example' => 'EUR'],
                    'account_type' => ['type' => 'string', 'enum' => ['checking', 'savings', 'escrow', 'credit_line', 'cash_pool_header']],
                    'current_balance' => ['type' => 'number', 'format' => 'float'],
                    'available_balance' => ['type' => 'number', 'format' => 'float'],
                    'overdraft_limit' => ['type' => 'number', 'format' => 'float'],
                    'is_default' => ['type' => 'boolean'],
                    'is_active' => ['type' => 'boolean'],
                ],
                'required' => ['id', 'tenant_id', 'bank_name', 'iban', 'currency'],
            ],
            'PaymentBatch' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'batch_reference' => ['type' => 'string'],
                    'bank_account_id' => ['type' => 'string', 'format' => 'uuid'],
                    'payment_method' => ['type' => 'string', 'example' => 'sepa_credit_transfer'],
                    'execution_date' => ['type' => 'string', 'format' => 'date'],
                    'total_amount' => ['type' => 'number', 'format' => 'float'],
                    'currency' => ['type' => 'string', 'example' => 'EUR'],
                    'item_count' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'pending_approval', 'approved', 'transmitted', 'cleared', 'rejected']],
                ],
                'required' => ['id', 'batch_reference', 'total_amount', 'status'],
            ],
            'CashForecast' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'forecast_number' => ['type' => 'string'],
                    'calculation_date' => ['type' => 'string', 'format' => 'date'],
                    'horizon_days' => ['type' => 'integer', 'example' => 90],
                    'opening_cash_balance' => ['type' => 'number', 'format' => 'float'],
                    'projected_inflows' => ['type' => 'number', 'format' => 'float'],
                    'projected_outflows' => ['type' => 'number', 'format' => 'float'],
                    'closing_cash_balance' => ['type' => 'number', 'format' => 'float'],
                    'deficit_detected' => ['type' => 'boolean'],
                    'deficit_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                ],
                'required' => ['id', 'forecast_number', 'calculation_date', 'closing_cash_balance'],
            ],
            'BankReconciliation' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'reconciliation_number' => ['type' => 'string'],
                    'bank_account_id' => ['type' => 'string', 'format' => 'uuid'],
                    'period_start_date' => ['type' => 'string', 'format' => 'date'],
                    'period_end_date' => ['type' => 'string', 'format' => 'date'],
                    'bank_statement_balance' => ['type' => 'number', 'format' => 'float'],
                    'gl_book_balance' => ['type' => 'number', 'format' => 'float'],
                    'difference_amount' => ['type' => 'number', 'format' => 'float'],
                    'status' => ['type' => 'string', 'enum' => ['in_progress', 'completed', 'approved', 'cancelled']],
                ],
                'required' => ['id', 'reconciliation_number', 'status'],
            ],
            'ReceivableCollection' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'customer_id' => ['type' => 'string', 'format' => 'uuid'],
                    'invoice_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                    'expected_amount' => ['type' => 'number', 'format' => 'float'],
                    'collected_amount' => ['type' => 'number', 'format' => 'float'],
                    'due_date' => ['type' => 'string', 'format' => 'date'],
                    'status' => ['type' => 'string', 'enum' => ['pending', 'collected', 'partial', 'overdue', 'defaulted']],
                    'aging_bucket' => ['type' => 'string', 'enum' => ['current', '1_30', '31_60', '61_90', 'over_90']],
                    'dunning_level' => ['type' => 'integer'],
                ],
                'required' => ['id', 'customer_id', 'expected_amount', 'due_date', 'status'],
            ],
        ];
    }
}
