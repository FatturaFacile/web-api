<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the supplier invoice approval API.
 */
#[OA\Tag(name: 'Supplier Invoice Approval', description: 'Multi-step approval through the workflow engine. The payment waits for the outcome.')]
#[OA\Schema(
    schema: 'InvoiceApprovalResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'approval_status', type: 'string', enum: ['not_required', 'pending', 'in_approval', 'approved', 'rejected']),
        new OA\Property(property: 'is_payment_blocked', type: 'boolean'),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'InvoiceApprovalMatrixResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'SubmitInvoiceApprovalRequest',
    type: 'object',
    properties: [new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true)]
)]
#[OA\Schema(
    schema: 'StoreInvoiceApprovalMatrixRequest',
    required: ['name', 'tiers'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true, description: 'Without it the matrix applies to every cost centre'),
        new OA\Property(property: 'tiers', type: 'array', minItems: 1, items: new OA\Items(
            required: ['tier_level', 'approver_type', 'approver_value'],
            properties: [
                new OA\Property(property: 'tier_level', type: 'integer', minimum: 1, description: 'Levels run one after the other, lowest first'),
                new OA\Property(property: 'approver_type', type: 'string', enum: ['role', 'user']),
                new OA\Property(property: 'approver_value', type: 'string', description: 'Role name or user id'),
                new OA\Property(property: 'threshold_min', type: 'string', nullable: true, description: 'The level applies from this amount'),
                new OA\Property(property: 'threshold_max', type: 'string', nullable: true, description: 'The level applies up to this amount'),
            ],
            type: 'object'
        )),
    ]
)]
final class InvoiceApprovalSchemas {}
