<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the module, derived from its form requests.
 */
#[OA\Schema(
    schema: 'HumanResourcesHireEmployeeRequest',
    description: 'Request payload validated by HireEmployeeRequest',
    required: ['first_name', 'last_name', 'work_email', 'base_annual_salary'],
    type: 'object',
    properties: [
        new OA\Property(property: 'first_name', type: 'string', maxLength: 100),
        new OA\Property(property: 'last_name', type: 'string', maxLength: 100),
        new OA\Property(property: 'work_email', type: 'string', format: 'email', maxLength: 180),
        new OA\Property(property: 'base_annual_salary', type: 'number', minimum: 0),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'team_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'business_unit_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'position_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'job_grade_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'manager_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'employment_type', type: 'string', nullable: true, enum: ['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal']),
        new OA\Property(property: 'contract_type', type: 'string', nullable: true, enum: ['permanent', 'fixed_term', 'apprenticeship', 'contractor', 'internship']),
        new OA\Property(property: 'weekly_hours', type: 'number', minimum: 1, maximum: 60, nullable: true),
        new OA\Property(property: 'probation_months', type: 'integer', minimum: 0, maximum: 12, nullable: true),
        new OA\Property(property: 'employee_number'),
        new OA\Property(property: 'user_id'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesPromoteEmployeeRequest',
    description: 'Request payload validated by PromoteEmployeeRequest',
    required: ['new_base_annual_salary'],
    type: 'object',
    properties: [
        new OA\Property(property: 'new_position_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'new_job_grade_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'new_base_annual_salary', type: 'number', minimum: 0),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTransferEmployeeRequest',
    description: 'Request payload validated by TransferEmployeeRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'to_department_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_team_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_business_unit_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_position_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'to_manager_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesTerminateEmployeeRequest',
    description: 'Request payload validated by TerminateEmployeeRequest',
    required: ['termination_date', 'reason'],
    type: 'object',
    properties: [
        new OA\Property(property: 'termination_date', type: 'string', format: 'date'),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000),
        new OA\Property(property: 'start_offboarding', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesSubmitLeaveRequestFormRequest',
    description: 'Request payload validated by SubmitLeaveRequestFormRequest',
    required: ['employee_id', 'leave_type_id', 'start_date', 'end_date', 'total_hours'],
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'leave_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', description: 'Must be on or after `start_date`.'),
        new OA\Property(property: 'total_hours', type: 'number', minimum: 0.5),
        new OA\Property(property: 'reason', type: 'string', maxLength: 1000, nullable: true),
        new OA\Property(property: 'medical_certificate_ref', type: 'string', maxLength: 120, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesSubmitTimesheetRequest',
    description: 'Request payload validated by SubmitTimesheetRequest',
    required: ['employee_id', 'work_date', 'regular_hours'],
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'work_date', type: 'string', format: 'date'),
        new OA\Property(property: 'regular_hours', type: 'number', minimum: 0, maximum: 24),
        new OA\Property(property: 'overtime_hours', type: 'number', minimum: 0, maximum: 24, nullable: true),
        new OA\Property(property: 'billable_hours', type: 'number', minimum: 0, maximum: 24, nullable: true),
        new OA\Property(property: 'project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesStoreTimeEventRequest',
    description: 'Request payload validated by StoreTimeEventRequest',
    required: ['event_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'event_type', type: 'string', enum: ['In', 'Out', 'BreakStart', 'BreakEnd']),
        new OA\Property(property: 'source', type: 'string', nullable: true, enum: ['badge', 'terminal', 'web', 'mobile', 'spreadsheet', 'paper', 'manual']),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
        new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000, nullable: true, description: 'Conditionally required.'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesCorrectTimeEventRequest',
    description: 'Request payload validated by CorrectTimeEventRequest',
    required: ['occurred_at', 'event_type', 'source', 'reason'],
    type: 'object',
    properties: [
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date'),
        new OA\Property(property: 'event_type', type: 'string', enum: ['In', 'Out', 'BreakStart', 'BreakEnd']),
        new OA\Property(property: 'source', type: 'string', enum: ['badge', 'terminal', 'web', 'mobile', 'spreadsheet', 'paper', 'manual']),
        new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
        new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesSaveTimeDailyRecordRequest',
    description: 'Request payload validated by SaveTimeDailyRecordRequest',
    required: ['date', 'worked_minutes'],
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'worked_minutes', type: 'integer', minimum: 0, maximum: 1440),
        new OA\Property(property: 'start_time', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'end_time', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
        new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesSaveTimeProfileRequest',
    description: 'Request payload validated by SaveTimeProfileRequest',
    required: ['mode', 'legal_max_weekly_hours', 'weekly_hours', 'work_days_per_week'],
    type: 'object',
    properties: [
        new OA\Property(property: 'mode', type: 'string', enum: ['Detailed', 'Simplified', 'Waived']),
        new OA\Property(property: 'agreement_date', type: 'string', format: 'date', nullable: true, description: 'Date of the agreement. Required for Simplified and Waived.'),
        new OA\Property(property: 'agreement_valid_from', type: 'string', format: 'date', nullable: true, description: 'First day covered by the agreement. Required for Simplified and Waived.'),
        new OA\Property(property: 'agreement_valid_to', type: 'string', format: 'date', nullable: true, description: 'Last day covered by the agreement. Days outside it fall back to detailed recording.'),
        new OA\Property(property: 'agreement_basis', type: 'string', enum: ['collective', 'individual'], nullable: true, description: 'Required for Simplified. Individual agreements are allowed below 50 employees (art. 73b OLL 1).'),
        new OA\Property(property: 'autonomy_percent', type: 'integer', minimum: 25, maximum: 100, nullable: true, description: 'Share of the working time the employee organises alone. Required for Simplified.'),
        new OA\Property(property: 'collective_agreement_reference', type: 'string', maxLength: 120, nullable: true, description: 'Collective labour agreement allowing the waiver. Required for Waived.'),
        new OA\Property(property: 'annual_gross_salary', type: 'number', nullable: true, description: 'Annual gross salary in CHF, bonuses included, above 120000. Required for Waived, stored encrypted and never returned.'),
        new OA\Property(property: 'legal_max_weekly_hours', type: 'integer', enum: ['45', '50']),
        new OA\Property(property: 'weekly_hours', type: 'number', minimum: 0, maximum: 168),
        new OA\Property(property: 'work_days_per_week', type: 'integer', minimum: 1, maximum: 7),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true, description: 'Must be on or after `hire_date`.'),
        new OA\Property(property: 'canton', type: 'string', nullable: true, enum: ['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH']),
        new OA\Property(property: 'reason', type: 'string', minLength: 3, maxLength: 1000, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesSubmitPerformanceReviewRequest',
    description: 'Request payload validated by SubmitPerformanceReviewRequest',
    required: ['employee_id', 'review_period', 'goals_score', 'competencies_score'],
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'review_period', type: 'string', maxLength: 30),
        new OA\Property(property: 'goals_score', type: 'number', minimum: 1, maximum: 5),
        new OA\Property(property: 'competencies_score', type: 'number', minimum: 1, maximum: 5),
        new OA\Property(property: 'reviewer_employee_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'review_type', type: 'string', nullable: true, enum: ['annual', 'mid_year', 'probation', 'promotion_readiness']),
        new OA\Property(property: 'promotion_recommended', type: 'boolean', nullable: true),
        new OA\Property(property: 'recommended_job_grade_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'recommended_salary_increase_percent', type: 'number', minimum: 0, maximum: 100, nullable: true),
        new OA\Property(property: 'successor_readiness', type: 'string', nullable: true, enum: ['ready_now', 'ready_1_2_years', 'not_ready']),
        new OA\Property(property: 'goals', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'competencies', type: 'array', nullable: true, items: new OA\Items),
        new OA\Property(property: 'strengths', type: 'string', nullable: true),
        new OA\Property(property: 'improvement_areas', type: 'string', nullable: true),
        new OA\Property(property: 'career_path_notes'),
    ]
)]
#[OA\Schema(
    schema: 'HumanResourcesAssignTrainingRequest',
    description: 'Request payload validated by AssignTrainingRequest',
    required: ['employee_id', 'course_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'course_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'scheduled_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'override_cost', type: 'number', minimum: 0, nullable: true),
        new OA\Property(property: 'notes', type: 'string', maxLength: 1000, nullable: true),
    ]
)]
final class HumanResourcesRequestSchemas {}
