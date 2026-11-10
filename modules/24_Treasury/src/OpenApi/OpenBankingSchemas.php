<?php

declare(strict_types=1);

namespace Modules\Treasury\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of open banking and of the IBAN check.
 */
#[OA\Tag(name: 'Treasury Open Banking', description: 'PSD2 account information and payment initiation. They refuse to run until a provider exists.')]
#[OA\Schema(schema: 'TreasuryCheckIbanRequest', required: ['iban'], type: 'object', properties: [new OA\Property(property: 'iban', type: 'string', maxLength: 64)])]
#[OA\Schema(
    schema: 'TreasuryIbanCheck',
    type: 'object',
    properties: [
        new OA\Property(property: 'iban', type: 'string'),
        new OA\Property(property: 'is_valid', type: 'boolean'),
        new OA\Property(property: 'country', type: 'string', nullable: true),
        new OA\Property(property: 'reason', type: 'string', nullable: true, enum: ['invalid_format', 'unknown_country', 'invalid_length', 'invalid_checksum']),
    ]
)]
#[OA\Schema(schema: 'TreasuryStoreOpenBankingConsentRequest', required: ['iban'], type: 'object', properties: [new OA\Property(property: 'iban', type: 'string')])]
#[OA\Schema(
    schema: 'TreasuryOpenBankingConsent',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'driver', type: 'string'),
        new OA\Property(property: 'iban', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryInitiateOpenBankingPaymentRequest',
    required: ['consent_id', 'creditor_iban', 'creditor_name', 'amount', 'currency'],
    type: 'object',
    properties: [
        new OA\Property(property: 'consent_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'creditor_iban', type: 'string'),
        new OA\Property(property: 'creditor_name', type: 'string', maxLength: 140),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3),
        new OA\Property(property: 'remittance_information', type: 'string', maxLength: 140, nullable: true),
        new OA\Property(property: 'passive_invoice_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryOpenBankingPayment',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'creditor_iban', type: 'string'),
        new OA\Property(property: 'amount', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'external_payment_id', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'TreasuryOpenBankingAccountInformation',
    type: 'object',
    properties: [
        new OA\Property(property: 'balance', type: 'object', properties: [
            new OA\Property(property: 'iban', type: 'string'),
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
            new OA\Property(property: 'balance', type: 'string', example: '0.00'),
        ]),
        new OA\Property(property: 'transactions', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'external_id', type: 'string'),
            new OA\Property(property: 'booked_at', type: 'string'),
            new OA\Property(property: 'amount', type: 'string', example: '120.50'),
            new OA\Property(property: 'currency', type: 'string'),
            new OA\Property(property: 'description', type: 'string'),
        ])),
    ]
)]
final class OpenBankingSchemas {}
