<?php

declare(strict_types=1);

namespace Modules\Workflow\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'WorkflowDefinitionResource',
    description: 'JSON representation produced by WorkflowDefinitionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'target_entity_type'),
        new OA\Property(property: 'version', type: 'integer', nullable: true),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'configuration'),
        new OA\Property(property: 'published_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowInstanceResource',
    description: 'JSON representation produced by WorkflowInstanceResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'workflow_definition_id', type: 'string'),
        new OA\Property(property: 'workflow_definition_version', type: 'integer', nullable: true),
        new OA\Property(property: 'approvable_type'),
        new OA\Property(property: 'approvable_id', type: 'string'),
        new OA\Property(property: 'current_node_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'locked_fiscal_document', type: 'boolean', nullable: true),
        new OA\Property(property: 'context_snapshot'),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'steps', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowInstanceStepResource')),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowInstanceStepResource',
    description: 'JSON representation produced by WorkflowInstanceStepResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'workflow_instance_id', type: 'string'),
        new OA\Property(property: 'workflow_node_id', type: 'string'),
        new OA\Property(property: 'assigned_user_id', type: 'string'),
        new OA\Property(property: 'assigned_role'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'tier_level', type: 'integer', nullable: true),
        new OA\Property(property: 'due_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'decisions', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowApprovalDecisionResource')),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowApprovalDecisionResource',
    description: 'JSON representation produced by ApprovalDecisionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'workflow_instance_step_id', type: 'string'),
        new OA\Property(property: 'user_id', type: 'string'),
        new OA\Property(property: 'effective_user_id', type: 'string'),
        new OA\Property(property: 'decision'),
        new OA\Property(property: 'comment'),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowAuditEntryResource',
    description: 'JSON representation produced by WorkflowAuditEntryResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'workflow_instance_id', type: 'string'),
        new OA\Property(property: 'workflow_instance_step_id', type: 'string'),
        new OA\Property(property: 'event_type'),
        new OA\Property(property: 'event_payload'),
        new OA\Property(property: 'actor_user_id', type: 'string'),
        new OA\Property(property: 'hmac_signature'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowDelegationModel',
    description: 'Attributes of the Workflow Delegation record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
final class WorkflowResponseSchemas {}
