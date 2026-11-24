<?php

declare(strict_types=1);

namespace Modules\Rebates\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the rebates and trade promotions endpoints.
 */
#[OA\Tag(name: 'Rebates', description: 'Rebates and trade promotions: volume rebate agreements with customers and suppliers, tiers, accrual from invoiced volume, settlement as credit note draft, promotions with date ranges')]
#[OA\Response(response: 'RebatesUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'RebatesForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'RebatesNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'RebatesUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'RebateTier', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'threshold_amount', description: 'Cumulative volume from which the rate applies', type: 'string', example: '10000.00'),
    new OA\Property(property: 'rate_percent', type: 'string', example: '2.5000'),
])]
#[OA\Schema(schema: 'RebateTierRequest', type: 'object', required: ['threshold_amount', 'rate_percent'], properties: [
    new OA\Property(property: 'threshold_amount', description: 'Distinct inside the scale', type: 'string', example: '10000'),
    new OA\Property(property: 'rate_percent', type: 'string', example: '2.5'),
])]
#[OA\Schema(schema: 'RebateAgreement', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'direction', description: 'sales: rebate owed to a customer; purchase: rebate owed by a supplier', type: 'string', enum: ['sales', 'purchase']),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_name', type: 'string', nullable: true),
    new OA\Property(property: 'tier_mode', type: 'string', enum: ['retroactive', 'incremental']),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'closed', 'cancelled']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(ref: '#/components/schemas/RebateTier')),
    new OA\Property(property: 'open_rebate', description: 'Accrued and not settled yet (list and show only)', type: 'string', example: '250.00'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'RebateAgreementRequest', type: 'object', required: ['code', 'name', 'direction', 'contact_id', 'starts_on', 'ends_on', 'tiers'], properties: [
    new OA\Property(property: 'code', description: 'Unique in the company', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'direction', type: 'string', enum: ['sales', 'purchase']),
    new OA\Property(property: 'contact_id', description: 'Customer or supplier of the company', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tier_mode', type: 'string', enum: ['retroactive', 'incremental']),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'tiers', type: 'array', minItems: 1, maxItems: 20, items: new OA\Items(ref: '#/components/schemas/RebateTierRequest')),
])]
#[OA\Schema(schema: 'RebateCalculateRequest', type: 'object', properties: [
    new OA\Property(property: 'as_of', description: 'Default today; inside the agreement period', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'RebateSettleRequest', type: 'object', properties: [
    new OA\Property(property: 'tax_rate', description: 'VAT rate of the credit note line; required for customer agreements', type: 'string', nullable: true, example: '22'),
    new OA\Property(property: 'settled_on', description: 'Default today', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'RebateAccrual', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'agreement_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'as_of', type: 'string', format: 'date'),
    new OA\Property(property: 'base_amount', description: 'Net volume invoiced from the start of the agreement to as_of', type: 'string', example: '25000.00'),
    new OA\Property(property: 'cumulative_rebate', description: 'Rebate earned on the volume so far', type: 'string'),
    new OA\Property(property: 'rebate_amount', description: 'Part not accrued before; negative when credit notes lowered the volume', type: 'string'),
    new OA\Property(property: 'applied_rate_percent', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['accrued', 'settled']),
    new OA\Property(property: 'settlement_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'RebateCalculation', type: 'object', properties: [
    new OA\Property(property: 'accrual', ref: '#/components/schemas/RebateAccrual', nullable: true),
    new OA\Property(property: 'base_amount', type: 'string'),
    new OA\Property(property: 'cumulative_rebate', type: 'string'),
    new OA\Property(property: 'previously_accrued', type: 'string'),
    new OA\Property(property: 'delta', type: 'string'),
])]
#[OA\Schema(schema: 'RebateSettlement', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'agreement_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'agreement', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'direction', type: 'string')]),
    new OA\Property(property: 'amount', type: 'string', example: '250.00'),
    new OA\Property(property: 'currency', type: 'string'),
    new OA\Property(property: 'settlement_type', type: 'string', enum: ['credit_note_draft', 'supplier_claim']),
    new OA\Property(property: 'credit_note_id', description: 'Credit note draft of invoicing (customer agreements)', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'settled_on', type: 'string', format: 'date'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'accruals', type: 'array', items: new OA\Items(ref: '#/components/schemas/RebateAccrual')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'RebatePromotion', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'discount_type', type: 'string', enum: ['percentage', 'fixed_amount']),
    new OA\Property(property: 'discount_value', type: 'string', example: '10.0000'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'min_quantity', type: 'string', nullable: true),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'state', description: 'Today: inactive, scheduled, active or expired', type: 'string', enum: ['inactive', 'scheduled', 'active', 'expired']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'RebatePromotionRequest', type: 'object', required: ['code', 'name', 'discount_type', 'discount_value', 'starts_on', 'ends_on'], properties: [
    new OA\Property(property: 'code', description: 'Unique in the company', type: 'string', maxLength: 40),
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'discount_type', type: 'string', enum: ['percentage', 'fixed_amount']),
    new OA\Property(property: 'discount_value', description: 'Up to 100 for a percentage', type: 'string', example: '10'),
    new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'min_quantity', type: 'string', nullable: true),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'RebateDashboard', type: 'object', properties: [
    new OA\Property(property: 'agreements', type: 'object', properties: [
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'by_status', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
    ]),
    new OA\Property(property: 'accrued_open', type: 'object', properties: [new OA\Property(property: 'sales', type: 'string'), new OA\Property(property: 'purchase', type: 'string')]),
    new OA\Property(property: 'settled', type: 'object', properties: [new OA\Property(property: 'sales', type: 'string'), new OA\Property(property: 'purchase', type: 'string')]),
    new OA\Property(property: 'promotions', type: 'object', properties: [new OA\Property(property: 'active', type: 'integer'), new OA\Property(property: 'scheduled', type: 'integer'), new OA\Property(property: 'expired', type: 'integer')]),
    new OA\Property(property: 'ending_agreements', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    ])),
])]
final class RebatesOpenApi {}
