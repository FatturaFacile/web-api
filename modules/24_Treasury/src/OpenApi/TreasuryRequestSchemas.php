<?php

declare(strict_types=1);

namespace Modules\Treasury\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'TreasuryStoreBankAccountRequest',
    description: 'Request payload validated by StoreBankAccountRequest',
    required: ['bank_name', 'iban', 'currency'],
    type: 'object',
    properties: [
        new OA\Property(property: 'bank_name', type: 'string', maxLength: 150),
        new OA\Property(property: 'branch_name', type: 'string', maxLength: 150, nullable: true),
        new OA\Property(property: 'iban', type: 'string', maxLength: 34),
        new OA\Property(property: 'bic_swift', type: 'string', maxLength: 11, nullable: true),
        new OA\Property(property: 'account_number', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3),
        new OA\Property(property: 'account_type', type: 'string', nullable: true, enum: ['checking', 'savings', 'escrow', 'credit_line', 'cash_pool_header']),
        new OA\Property(property: 'gl_account_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'overdraft_limit', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'opening_balance', type: 'number', nullable: true),
        new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryStorePaymentBatchRequest',
    description: 'Request payload validated by StorePaymentBatchRequest',
    required: ['bank_account_id', 'lines'],
    type: 'object',
    properties: [
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'batch_reference', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'payment_method', type: 'string', nullable: true, enum: ['sepa_credit_transfer', 'sepa_instant', 'sepa_direct_debit', 'swiss_qr_pain001', 'riba', 'f24']),
        new OA\Property(property: 'execution_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true),
        new OA\Property(property: 'lines', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['recipient_name', 'recipient_iban', 'amount'], properties: [new OA\Property(property: 'recipient_name', type: 'string', maxLength: 255), new OA\Property(property: 'recipient_iban', type: 'string', maxLength: 34), new OA\Property(property: 'recipient_bic', type: 'string', maxLength: 11, nullable: true), new OA\Property(property: 'amount', type: 'number'), new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true), new OA\Property(property: 'remittance_reference', type: 'string', maxLength: 140, nullable: true), new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid', nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryImportStatementRequest',
    description: 'Request payload validated by ImportStatementRequest',
    required: ['bank_account_id', 'lines'],
    type: 'object',
    properties: [
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'statement_number', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'statement_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'format', type: 'string', nullable: true, enum: ['CAMT_053', 'CAMT_052', 'MT940', 'MT942', 'CSV']),
        new OA\Property(property: 'opening_balance', type: 'number', nullable: true),
        new OA\Property(property: 'closing_balance', type: 'number', nullable: true),
        new OA\Property(property: 'lines', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['amount'], properties: [new OA\Property(property: 'booking_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'value_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'amount', type: 'number'), new OA\Property(property: 'bank_reference', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'customer_reference', type: 'string', maxLength: 100, nullable: true), new OA\Property(property: 'counterparty_name', type: 'string', maxLength: 255, nullable: true), new OA\Property(property: 'counterparty_iban', type: 'string', maxLength: 34, nullable: true), new OA\Property(property: 'remittance_information', type: 'string', nullable: true)])),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryRunReconciliationRequest',
    description: 'Request payload validated by RunReconciliationRequest',
    required: ['bank_statement_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'bank_statement_id', type: 'string', format: 'uuid'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryGenerateForecastRequest',
    description: 'Request payload validated by GenerateForecastRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'scenario_code', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'horizon_days', type: 'integer', nullable: true, enum: ['30', '60', '90', '180', '360']),
        new OA\Property(property: 'calculation_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryIntercompanyTransferRequest',
    description: 'Request payload validated by IntercompanyTransferRequest',
    required: ['source_bank_account_id', 'destination_bank_account_id', 'amount'],
    type: 'object',
    properties: [
        new OA\Property(property: 'source_bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'destination_bank_account_id', type: 'string', format: 'uuid', description: 'Must differ from `source_bank_account_id`.'),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'transfer_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'intercompany_interest_rate', type: 'number', minimum: 0, maximum: 1, nullable: true),
        new OA\Property(property: 'cash_pool_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
final class TreasuryRequestSchemas {}
