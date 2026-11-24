<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of donations, campaigns, the 5x1000 and sponsorships.
 */
#[OA\Schema(schema: 'AssociationDonationRequest', required: ['kind', 'amount', 'received_on'], properties: [
    new OA\Property(property: 'donor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kind', type: 'string', enum: ['money', 'in_kind']),
    new OA\Property(property: 'amount', description: 'For a gift in kind, the value declared', type: 'string', example: '50.00'),
    new OA\Property(property: 'in_kind_description', type: 'string', nullable: true),
    new OA\Property(property: 'received_on', type: 'string', format: 'date'),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'bank'], nullable: true),
    new OA\Property(property: 'purpose', type: 'string', nullable: true),
    new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationExpenseRequest', required: ['incurred_on', 'description', 'amount'], properties: [
    new OA\Property(property: 'incurred_on', type: 'string', format: 'date'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'amount', type: 'string', example: '120.00'),
    new OA\Property(property: 'expense_account_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'bank']),
    new OA\Property(property: 'category_id', description: '5x1000 expenses only', type: 'string', format: 'uuid'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationFivePerMilleRequest', required: ['reference_year', 'received_on', 'amount'], properties: [
    new OA\Property(property: 'reference_year', type: 'string', example: '2025'),
    new OA\Property(property: 'received_on', type: 'string', format: 'date'),
    new OA\Property(property: 'amount', type: 'string'),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'bank']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationCampaignReport', properties: [
    new OA\Property(property: 'campaign', type: 'object', additionalProperties: true),
    new OA\Property(property: 'donations', properties: [
        new OA\Property(property: 'count', type: 'integer'),
        new OA\Property(property: 'money', type: 'string'),
        new OA\Property(property: 'in_kind', type: 'string'),
    ], type: 'object'),
    new OA\Property(property: 'expenses', type: 'string'),
    new OA\Property(property: 'reimbursements', type: 'string'),
    new OA\Property(property: 'result', type: 'string'),
    new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'kind', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'amount', type: 'string'),
    ], type: 'object')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationFivePerMilleReport', properties: [
    new OA\Property(property: 'allocation', type: 'object', additionalProperties: true),
    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'amount', type: 'string'),
    ], type: 'object')),
    new OA\Property(property: 'spent', type: 'string'),
    new OA\Property(property: 'remaining', type: 'string'),
], type: 'object')]
final class FundraisingOpenApiSchemas {}
