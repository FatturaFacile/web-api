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
#[OA\Schema(
    schema: 'WorkflowSaveDefinitionRequest',
    description: 'Request payload validated by SaveWorkflowDefinitionRequest. On POST code, name and target_entity_type are required and nodes/transitions/publish are accepted; on PUT and PATCH code, nodes, transitions and publish are refused.',
    type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 100, pattern: '^[A-Z][A-Z0-9_]*$', example: 'PURCHASE_ORDER_APPROVAL'),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'target_entity_type', type: 'string', maxLength: 255),
        new OA\Property(property: 'configuration', type: 'object'),
        new OA\Property(property: 'is_active', description: 'PUT/PATCH only; true requires a published definition', type: 'boolean'),
        new OA\Property(property: 'nodes', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowSaveNodeRequest')),
        new OA\Property(property: 'transitions', type: 'array', items: new OA\Items(type: 'object', required: ['from', 'to', 'action_trigger'], properties: [
            new OA\Property(property: 'from', description: 'node_key of the source node', type: 'string'),
            new OA\Property(property: 'to', description: 'node_key of the target node', type: 'string'),
            new OA\Property(property: 'action_trigger', type: 'string', enum: ['start', 'approved', 'rejected', 'timeout', 'condition_true', 'condition_false', '*']),
            new OA\Property(property: 'priority', type: 'integer'),
            new OA\Property(property: 'condition_rules', type: 'array', items: new OA\Items(type: 'object')),
        ])),
        new OA\Property(property: 'publish', description: 'POST only: validate the graph and publish at once', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSaveNodeRequest',
    description: 'A workflow node. Approval nodes need assignee_type and, except for reporting_manager and matrix assignees, assignee_id (a user id or a role such as admin).',
    required: ['node_key', 'name', 'node_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'node_key', type: 'string', maxLength: 100, pattern: '^[a-z0-9_]+$'),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'node_type', type: 'string', enum: ['start', 'approval', 'conditional', 'notification', 'end']),
        new OA\Property(property: 'approval_type', type: 'string', enum: ['single', 'and_parallel', 'or_parallel', 'majority', 'supermajority', 'unanimous'], nullable: true),
        new OA\Property(property: 'assignee_type', type: 'string', enum: ['direct_user', 'role', 'reporting_manager', 'matrix_lookup', 'matrix_sequential'], nullable: true),
        new OA\Property(property: 'assignee_id', type: 'string', nullable: true),
        new OA\Property(property: 'timeout_hours', type: 'integer', nullable: true),
        new OA\Property(property: 'ui_metadata', type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSaveTransitionRequest',
    description: 'A transition between two nodes of the same definition',
    required: ['from_node_id', 'to_node_id', 'action_trigger'],
    type: 'object',
    properties: [
        new OA\Property(property: 'from_node_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'to_node_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'action_trigger', type: 'string', enum: ['start', 'approved', 'rejected', 'timeout', 'condition_true', 'condition_false', '*']),
        new OA\Property(property: 'priority', type: 'integer'),
        new OA\Property(property: 'condition_rules', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
final class WorkflowRequestSchemas {}
