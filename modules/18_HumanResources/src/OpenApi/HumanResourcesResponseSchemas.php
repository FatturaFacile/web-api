<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the module, derived from its API resources and models.
 */
#[OA\Schema(
    schema: 'HumanResourcesEmployeeResource',
    description: 'JSON representation produced by EmployeeResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string'),
        new OA\Property(property: 'employee_number'),
        new OA\Property(property: 'first_name'),
        new OA\Property(property: 'last_name'),
        new OA\Property(property: 'full_name'),
        new OA\Property(property: 'work_email'),
        new OA\Property(property: 'department_id', type: 'string'),
        new OA\Property(property: 'team_id', type: 'string'),
        new OA\Property(property: 'business_unit_id', type: 'string'),
        new OA\Property(property: 'cost_center_id', type: 'string'),
        new OA\Property(property: 'position_id', type: 'string'),
        new OA\Property(property: 'job_grade_id', type: 'string'),
        new OA\Property(property: 'manager_id', type: 'string'),
        new OA\Property(property: 'employment_status'),
        new OA\Property(property: 'employment_type'),
        new OA\Property(property: 'hire_date', type: 'string'),
        new OA\Property(property: 'probation_end_date', type: 'string'),
        new OA\Property(property: 'termination_date', type: 'string'),
        new OA\Property(property: 'base_annual_salary', type: 'number'),
        new OA\Property(property: 'hourly_cost_rate', type: 'number'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'fte_ratio', type: 'number'),
        new OA\Property(property: 'approval_status'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'equipment_assigned', type: 'array', items: new OA\Items),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesEmployeeLifecycleEventModel',
    description: 'Attributes of the Employee Lifecycle Event record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'from_salary', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'to_salary', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'is_locked', type: 'boolean', nullable: true),
        new OA\Property(property: 'snapshot'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesDepartmentResource',
    description: 'JSON representation produced by DepartmentResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'parent_id', type: 'string'),
        new OA\Property(property: 'business_unit_id', type: 'string'),
        new OA\Property(property: 'cost_center_id', type: 'string'),
        new OA\Property(property: 'manager_employee_id', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'translations', type: 'array', items: new OA\Items),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesPositionResource',
    description: 'JSON representation produced by PositionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'department_id', type: 'string'),
        new OA\Property(property: 'job_grade_id', type: 'string'),
        new OA\Property(property: 'headcount_budget', type: 'integer'),
        new OA\Property(property: 'is_critical_role', type: 'boolean'),
        new OA\Property(property: 'successor_employee_id', type: 'string'),
        new OA\Property(property: 'readiness_level'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesLeaveTypeModel',
    description: 'Attributes of the Leave Type record, as serialized by the API (the whole model: GET /hr/leave-types needs HR access; employees use GET /hr/me/leave-types)',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'company_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'code', type: 'string', example: 'VAC'),
        new OA\Property(property: 'name', type: 'string', example: 'Vacation'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'accrual_frequency', type: 'string'),
        new OA\Property(property: 'is_paid', type: 'boolean', nullable: true),
        new OA\Property(property: 'requires_approval', type: 'boolean', nullable: true),
        new OA\Property(property: 'requires_attachment', type: 'boolean', nullable: true),
        new OA\Property(property: 'annual_entitlement_hours', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'max_carryover_hours', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'translations'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesLeaveBalanceResource',
    description: 'Leave balance of an employee, leave type and fiscal year. The *_hours fields are JSON numbers (the resource casts them to float), not decimal strings.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'leave_type_id', type: 'string'),
        new OA\Property(property: 'fiscal_year', type: 'integer'),
        new OA\Property(property: 'entitlement_hours', type: 'number'),
        new OA\Property(property: 'carryover_hours', type: 'number'),
        new OA\Property(property: 'accrued_hours', type: 'number'),
        new OA\Property(property: 'used_hours', type: 'number'),
        new OA\Property(property: 'pending_hours', type: 'number'),
        new OA\Property(property: 'available_hours', type: 'number'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesLeaveRequestResource',
    description: 'JSON representation produced by LeaveRequestResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'request_number', type: 'string', example: 'LR-202606-0001'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'leave_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'total_hours', type: 'number', description: 'JSON number (float), not a decimal string'),
        new OA\Property(property: 'total_days', type: 'number', description: 'JSON number (float), not a decimal string'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'pending_approval', 'approved', 'rejected', 'cancelled']),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTimesheetResource',
    description: 'JSON representation produced by TimesheetResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'timesheet_number'),
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'project_id', type: 'string'),
        new OA\Property(property: 'work_date', type: 'string'),
        new OA\Property(property: 'regular_hours', type: 'number'),
        new OA\Property(property: 'overtime_hours', type: 'number'),
        new OA\Property(property: 'total_hours', type: 'number'),
        new OA\Property(property: 'billable_hours', type: 'number'),
        new OA\Property(property: 'hourly_cost_rate', type: 'number'),
        new OA\Property(property: 'total_labor_cost', type: 'number'),
        new OA\Property(property: 'status'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTimeClockEventResource',
    description: 'JSON representation produced by TimeClockEventResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'event_type', type: 'string'),
        new OA\Property(property: 'source', type: 'string'),
        new OA\Property(property: 'note'),
        new OA\Property(property: 'created_by'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'deleted_by'),
        new OA\Property(property: 'deletion_reason'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTimeDailyRecordResource',
    description: 'JSON representation produced by TimeDailyRecordResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'created_by'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTimeAuditEntryResource',
    description: 'JSON representation produced by TimeAuditEntryResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'entity_type'),
        new OA\Property(property: 'entity_id', type: 'string'),
        new OA\Property(property: 'action'),
        new OA\Property(property: 'before'),
        new OA\Property(property: 'after'),
        new OA\Property(property: 'reason'),
        new OA\Property(property: 'actor_id', type: 'string'),
        new OA\Property(property: 'actor_name'),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTimeRecordingProfileResource',
    description: 'JSON representation produced by TimeRecordingProfileResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'is_configured'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesPerformanceReviewResource',
    description: 'JSON representation produced by PerformanceReviewResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'review_number'),
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'review_period'),
        new OA\Property(property: 'review_type'),
        new OA\Property(property: 'overall_rating', type: 'number'),
        new OA\Property(property: 'goals_score', type: 'number'),
        new OA\Property(property: 'competencies_score', type: 'number'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'promotion_recommended', type: 'boolean'),
        new OA\Property(property: 'successor_readiness'),
        new OA\Property(property: 'is_locked', type: 'boolean'),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTrainingCourseModel',
    description: 'Attributes of the Training Course record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'duration_hours', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'cost_per_participant', type: 'string', nullable: true, description: 'Decimal value serialized as a string.'),
        new OA\Property(property: 'validity_months', type: 'integer', nullable: true),
        new OA\Property(property: 'is_mandatory_compliance', type: 'boolean', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
        new OA\Property(property: 'translations'),
        new OA\Property(property: 'syllabus'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTrainingRecordResource',
    description: 'JSON representation produced by TrainingRecordResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'enrollment_number'),
        new OA\Property(property: 'employee_id', type: 'string'),
        new OA\Property(property: 'course_id', type: 'string'),
        new OA\Property(property: 'status'),
        new OA\Property(property: 'assigned_date', type: 'string'),
        new OA\Property(property: 'completed_date', type: 'string'),
        new OA\Property(property: 'score', type: 'number'),
        new OA\Property(property: 'cost_amount', type: 'number'),
        new OA\Property(property: 'certificate_issued', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesCertificationModel',
    description: 'Attributes of the Certification record, as serialized by the API',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_mandatory', type: 'boolean', nullable: true),
        new OA\Property(property: 'renewal_alert_sent_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'translations'),
        new OA\Property(property: 'metadata'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
final class HumanResourcesResponseSchemas {}
