<?php

declare(strict_types=1);

namespace Modules\Workflow\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'WorkflowStartWorkflowRequest',
    description: 'Request payload validated by StartWorkflowRequest',
    required: ['workflow_code', 'approvable_type', 'approvable_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'workflow_code', type: 'string', maxLength: 100),
        new OA\Property(property: 'approvable_type', type: 'string'),
        new OA\Property(property: 'approvable_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'context_snapshot', type: 'array', items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowRecordDecisionRequest',
    description: 'Request payload validated by RecordDecisionRequest',
    required: ['decision'],
    type: 'object',
    properties: [
        new OA\Property(property: 'decision', type: 'string', enum: ['approved', 'rejected', 'abstained']),
        new OA\Property(property: 'comment', type: 'string', maxLength: 2000, nullable: true),
        new OA\Property(property: 'idempotency_key', type: 'string', maxLength: 64, nullable: true),
        new OA\Property(property: 'signature_payload', type: 'array', nullable: true, items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowCreateDelegationRequest',
    description: 'Request payload validated by CreateDelegationRequest',
    required: ['delegate_user_id', 'scope', 'starts_at', 'ends_at'],
    type: 'object',
    properties: [
        new OA\Property(property: 'delegate_user_id', type: 'string', format: 'uuid', description: 'Must differ from `delegator_user_id`.'),
        new OA\Property(property: 'scope', type: 'string', enum: ['all', 'procurement', 'expenses', 'invoicing', 'hr', 'custom']),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date', description: 'Must be after `starts_at`.'),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowConsumeRemoteTokenRequest',
    description: 'Request payload validated by ConsumeRemoteTokenRequest',
    required: ['token', 'action'],
    type: 'object',
    properties: [
        new OA\Property(property: 'token', type: 'string', minLength: 32),
        new OA\Property(property: 'action', type: 'string', enum: ['approve', 'reject']),
        new OA\Property(property: 'comment', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
final class WorkflowRequestSchemas {}
