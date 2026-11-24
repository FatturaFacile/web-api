<?php

declare(strict_types=1);

namespace Modules\Workflow\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the workflow inbox, SLA report, escalation rules and audit verification.
 */
#[OA\Tag(name: 'Workflow Inbox', description: 'Tasks waiting for me, my decisions, my requests and the SLA of approvals')]
#[OA\Tag(name: 'Workflow Escalation Rules', description: 'Reminders, escalation and automatic decisions for approvals that wait too long')]
#[OA\Schema(
    schema: 'WorkflowTask',
    description: 'A pending approval in the inbox of the caller',
    type: 'object',
    properties: [
        new OA\Property(property: 'step_id', type: 'string', format: 'uuid', description: 'Decide it with POST /workflows/steps/{stepId}/decide'),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'workflow_code', type: 'string', nullable: true, example: 'purchase_order_approval'),
        new OA\Property(property: 'workflow_name', type: 'string', nullable: true),
        new OA\Property(property: 'node_name', type: 'string', nullable: true),
        new OA\Property(property: 'approvable_type', type: 'string', nullable: true),
        new OA\Property(property: 'approvable_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'approvable_label', type: 'string', nullable: true, example: 'PO-2027-0042'),
        new OA\Property(property: 'initiated_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assigned_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assigned_role', type: 'string', nullable: true),
        new OA\Property(property: 'via_delegation_from', type: 'string', format: 'uuid', nullable: true, description: 'The colleague who delegated this step to the caller'),
        new OA\Property(property: 'tier_level', type: 'integer'),
        new OA\Property(property: 'due_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_overdue', type: 'boolean'),
        new OA\Property(property: 'hours_remaining', type: 'string', nullable: true, example: '5.5', description: 'Negative when overdue'),
        new OA\Property(property: 'escalation_level', type: 'integer'),
        new OA\Property(property: 'escalated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowTaskSummary',
    type: 'object',
    properties: [
        new OA\Property(property: 'pending', type: 'integer'),
        new OA\Property(property: 'overdue', type: 'integer'),
        new OA\Property(property: 'delegated', type: 'integer'),
        new OA\Property(property: 'escalated', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowHistoryEntry',
    type: 'object',
    properties: [
        new OA\Property(property: 'decision_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'decision', type: 'string', enum: ['approved', 'rejected']),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'on_behalf_of', type: 'string', format: 'uuid', nullable: true, description: 'The delegator, when the caller decided as their delegate'),
        new OA\Property(property: 'step_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'workflow_instance_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'workflow_code', type: 'string', nullable: true),
        new OA\Property(property: 'instance_status', type: 'string', nullable: true),
        new OA\Property(property: 'approvable_type', type: 'string', nullable: true),
        new OA\Property(property: 'approvable_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSlaFigures',
    type: 'object',
    properties: [
        new OA\Property(property: 'definition_code', type: 'string', description: 'Only in the per-definition rows'),
        new OA\Property(property: 'definition_name', type: 'string', description: 'Only in the per-definition rows'),
        new OA\Property(property: 'steps', type: 'integer'),
        new OA\Property(property: 'decided_on_time', type: 'integer'),
        new OA\Property(property: 'decided_late', type: 'integer'),
        new OA\Property(property: 'timed_out', type: 'integer'),
        new OA\Property(property: 'pending_overdue', type: 'integer'),
        new OA\Property(property: 'pending_on_track', type: 'integer'),
        new OA\Property(property: 'escalated', type: 'integer'),
        new OA\Property(property: 'breached', type: 'integer', description: 'Late, timed out or still waiting past the limit'),
        new OA\Property(property: 'breach_rate_percent', type: 'string', nullable: true, example: '12.50'),
        new OA\Property(property: 'avg_hours_to_decide', type: 'string', nullable: true, description: 'Null when no step was decided'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSlaReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'period', type: 'object', properties: [new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')]),
        new OA\Property(property: 'totals', ref: '#/components/schemas/WorkflowSlaFigures'),
        new OA\Property(property: 'by_definition', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowSlaFigures')),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowEscalationRule',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', example: 'Purchase approvals: remind at 24h, escalate at 48h'),
        new OA\Property(property: 'reminder_hours', type: 'integer', example: 24),
        new OA\Property(property: 'escalation_hours', type: 'integer', example: 48),
        new OA\Property(property: 'escalate_to_type', type: 'string', enum: ['reporting_manager', 'specific_role', 'specific_user']),
        new OA\Property(property: 'escalate_to_id', type: 'string', nullable: true, description: 'Role name or user id, depending on the type'),
        new OA\Property(property: 'auto_action', type: 'string', enum: ['none', 'auto_approve', 'auto_reject'], description: 'Decision the engine takes when the time limit of the step runs out'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSaveEscalationRuleRequest',
    type: 'object',
    required: ['name', 'reminder_hours', 'escalation_hours', 'escalate_to_type'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'reminder_hours', type: 'integer', minimum: 1, maximum: 8760, description: 'Business hours after the step opens'),
        new OA\Property(property: 'escalation_hours', type: 'integer', minimum: 1, maximum: 8760, description: 'Business hours after the step opens; must be later than the reminder'),
        new OA\Property(property: 'escalate_to_type', type: 'string', enum: ['reporting_manager', 'specific_role', 'specific_user']),
        new OA\Property(property: 'escalate_to_id', type: 'string', nullable: true, description: 'Required for specific_role and specific_user; a user must be a member of the company'),
        new OA\Property(property: 'auto_action', type: 'string', nullable: true, enum: ['none', 'auto_approve', 'auto_reject']),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowAuditVerification',
    type: 'object',
    properties: [
        new OA\Property(property: 'is_intact', type: 'boolean'),
        new OA\Property(property: 'entries', type: 'integer'),
        new OA\Property(property: 'verified', type: 'integer'),
        new OA\Property(property: 'legacy_unverifiable', type: 'integer', description: 'Entries written before the chain had a sequence: counted, not checked'),
        new OA\Property(property: 'first_broken_sequence', type: 'integer', nullable: true),
    ]
)]
final class WorkflowInboxSchemas {}
