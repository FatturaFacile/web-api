<?php

declare(strict_types=1);

namespace Modules\Workflow\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Schemas of the Mod 006 additions: matrices, calendars, lifecycle actions, the confirmation page of one-click tokens and
 * the inbox counter.
 */
#[OA\Schema(
    schema: 'WorkflowApprovalMatrix',
    description: 'Tiered approval matrix: conditions (all must hold) and cumulative tiers by amount range',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'subject_type', type: 'string', example: 'purchase_order'),
        new OA\Property(property: 'currency', type: 'string', nullable: true),
        new OA\Property(property: 'priority', type: 'integer', description: 'Lowest number is evaluated first.'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'is_default', type: 'boolean', description: 'The explicit default rule, used when no other matrix matches.'),
        new OA\Property(property: 'auto_approve_below', type: 'number', nullable: true),
        new OA\Property(property: 'fallback_approver', type: 'object', nullable: true, properties: [new OA\Property(property: 'approver_type', type: 'string'), new OA\Property(property: 'approver_value', type: 'string')]),
        new OA\Property(property: 'conditions', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowMatrixCondition')),
        new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowMatrixTier')),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowMatrixCondition',
    required: ['field', 'operator', 'value'],
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'field', type: 'string', example: 'cost_center_id'),
        new OA\Property(property: 'operator', type: 'string', enum: ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'between']),
        new OA\Property(property: 'value', description: 'Scalar, a list for in/not_in, or [min, max] for between.'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowMatrixTier',
    required: ['tier_level', 'approver_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'tier_level', type: 'integer', description: 'Levels run in sequence; tiers of one level in parallel.'),
        new OA\Property(property: 'approver_type', type: 'string', enum: ['role', 'user', 'reporting_manager', 'matrix_approver']),
        new OA\Property(property: 'approver_value', type: 'string', nullable: true),
        new OA\Property(property: 'approval_type', type: 'string', enum: ['single', 'and_parallel', 'or_parallel', 'majority', 'supermajority', 'unanimous']),
        new OA\Property(property: 'threshold_min', type: 'number', nullable: true),
        new OA\Property(property: 'threshold_max', type: 'number', nullable: true, description: 'Inclusive.'),
        new OA\Property(property: 'threshold_min_inclusive', type: 'boolean', description: 'False reads as "above threshold_min".'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowUpsertApprovalMatrixRequest',
    description: 'Request payload validated by UpsertApprovalMatrixRequest',
    required: ['name', 'subject_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'subject_type', type: 'string'),
        new OA\Property(property: 'currency', type: 'string', nullable: true),
        new OA\Property(property: 'priority', type: 'integer', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
        new OA\Property(property: 'auto_approve_below', type: 'number', nullable: true),
        new OA\Property(property: 'fallback_approver', type: 'object', nullable: true, properties: [new OA\Property(property: 'approver_type', type: 'string'), new OA\Property(property: 'approver_value', type: 'string')]),
        new OA\Property(property: 'conditions', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowMatrixCondition')),
        new OA\Property(property: 'tiers', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowMatrixTier')),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSimulateMatrixRequest',
    description: 'Request payload validated by SimulateMatrixRequest',
    required: ['subject_type', 'attributes'],
    type: 'object',
    properties: [
        new OA\Property(property: 'subject_type', type: 'string'),
        new OA\Property(property: 'matrix_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'attributes', type: 'object', description: 'amount, currency, cost_center_id, category, discount_percent, new_supplier, company_id'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSaveBusinessCalendarRequest',
    description: 'Request payload validated by SaveBusinessCalendarRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'timezone', type: 'string', nullable: true, example: 'Europe/Zurich'),
        new OA\Property(property: 'work_monday', type: 'boolean', nullable: true),
        new OA\Property(property: 'work_tuesday', type: 'boolean', nullable: true),
        new OA\Property(property: 'work_wednesday', type: 'boolean', nullable: true),
        new OA\Property(property: 'work_thursday', type: 'boolean', nullable: true),
        new OA\Property(property: 'work_friday', type: 'boolean', nullable: true),
        new OA\Property(property: 'work_saturday', type: 'boolean', nullable: true),
        new OA\Property(property: 'work_sunday', type: 'boolean', nullable: true),
        new OA\Property(property: 'work_hours_start', type: 'string', nullable: true, example: '08:30'),
        new OA\Property(property: 'work_hours_end', type: 'string', nullable: true, example: '17:30'),
        new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowStoreCalendarHolidayRequest',
    description: 'Request payload validated by StoreCalendarHolidayRequest',
    required: ['holiday_date', 'name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'holiday_date', type: 'string', format: 'date'),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'holiday_type', type: 'string', enum: ['national', 'cantonal', 'patron_saint', 'company'], nullable: true),
        new OA\Property(property: 'region', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowSeedCalendarHolidaysRequest',
    description: 'Request payload validated by SeedCalendarHolidaysRequest',
    required: ['country', 'year'],
    type: 'object',
    properties: [
        new OA\Property(property: 'country', type: 'string', enum: ['CH', 'IT']),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'canton', type: 'string', nullable: true, description: 'Swiss canton code (ZH, BE, LU, TI, GE, VD, BS, AG).'),
        new OA\Property(property: 'patron_saint', type: 'string', nullable: true, description: 'Italian city key or a literal MM-DD.'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowCancelInstanceRequest',
    description: 'Request payload validated by CancelWorkflowInstanceRequest',
    type: 'object',
    properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000, description: 'Mandatory when an administrator cancels an approval requested by somebody else.')]
)]
#[OA\Schema(
    schema: 'WorkflowRemoteApprovalPreview',
    description: 'What the confirmation page of a one-click link shows. Nothing is decided by reading it.',
    type: 'object',
    properties: [
        new OA\Property(property: 'requires_reauth', type: 'boolean'),
        new OA\Property(property: 'subject_label', type: 'string'),
        new OA\Property(property: 'subject_type', type: 'string', nullable: true),
        new OA\Property(property: 'amount', type: 'number', nullable: true),
        new OA\Property(property: 'currency', type: 'string', nullable: true),
        new OA\Property(property: 'requester_name', type: 'string', nullable: true),
        new OA\Property(property: 'approver_name', type: 'string', nullable: true),
        new OA\Property(property: 'action', type: 'string', enum: ['approve', 'reject']),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'WorkflowConfirmRemoteApprovalRequest',
    description: 'Request payload validated by ConfirmRemoteApprovalRequest',
    required: ['action', 'confirm'],
    type: 'object',
    properties: [
        new OA\Property(property: 'action', type: 'string', enum: ['approve', 'reject']),
        new OA\Property(property: 'confirm', type: 'boolean', description: 'Must be true: the decision is taken only on confirmation.'),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
        new OA\Property(property: 'password', type: 'string', nullable: true, description: 'Re-authentication above the amount threshold, for users without two-factor authentication.'),
        new OA\Property(property: 'otp', type: 'string', nullable: true, description: 'Re-authentication above the amount threshold, for users with two-factor authentication.'),
    ]
)]
final class WorkflowEngineSchemas {}
