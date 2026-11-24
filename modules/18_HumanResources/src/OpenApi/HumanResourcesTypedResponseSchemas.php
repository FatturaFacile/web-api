<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Typed requests and responses of the leave, self-service, training, succession and forecast endpoints of Human
 * Resources. Hours and days of leave balances and leave requests are JSON numbers (floats cast by the resources), not
 * decimal strings; amounts of the payroll forecast are numbers as well.
 */
#[OA\Schema(
    schema: 'HrSelfServiceProfile',
    description: 'Organisational data of the employee record linked to the user (no pay data)',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_number', type: 'string'),
        new OA\Property(property: 'full_name', type: 'string'),
        new OA\Property(property: 'work_email', type: 'string', nullable: true),
        new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'position_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'manager_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'employment_status', type: 'string'),
        new OA\Property(property: 'employment_type', type: 'string'),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'fte_ratio', type: 'string', description: 'Decimal string, 1.00 = full time', example: '1.00'),
    ]
)]
#[OA\Schema(
    schema: 'HrSelfServiceProfileResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HrSelfServiceProfile')]
)]
#[OA\Schema(
    schema: 'HrSelfServiceLeaveType',
    description: 'Label data of an absence type, for the employee screens (balances, requests). Includes inactive types, which can still be on old balances and requests. The entitlement rules stay with GET /hr/leave-types.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', example: 'VAC'),
        new OA\Property(property: 'name', type: 'string', example: 'Vacation'),
        new OA\Property(property: 'category', type: 'string'),
        new OA\Property(property: 'is_paid', type: 'boolean'),
        new OA\Property(property: 'requires_approval', type: 'boolean'),
        new OA\Property(property: 'requires_attachment', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'translations', type: 'object', nullable: true, description: 'Localised names by language code'),
    ]
)]
#[OA\Schema(
    schema: 'HrSelfServiceLeaveTypeListResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HrSelfServiceLeaveType'))]
)]
#[OA\Schema(
    schema: 'HrLeaveBalanceListResponse',
    description: 'Leave balances, newest fiscal year first on the self-service route. Hours are JSON numbers (floats).',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesLeaveBalanceResource'))]
)]
#[OA\Schema(
    schema: 'HrLeaveRequestListResponse',
    description: 'Leave requests. Hours and days are JSON numbers (floats).',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesLeaveRequestResource'))]
)]
#[OA\Schema(
    schema: 'HrLeaveRequestResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesLeaveRequestResource')]
)]
#[OA\Schema(
    schema: 'HrSelfServiceTrainingRecord',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'course_code', type: 'string', nullable: true),
        new OA\Property(property: 'course_title', type: 'string', nullable: true),
        new OA\Property(property: 'category', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'assigned_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'scheduled_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'completed_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HrSelfServiceCertification',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', description: 'active, expiring_soon, expired'),
    ]
)]
#[OA\Schema(
    schema: 'HrSelfServiceTrainingResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'training_records', type: 'array', items: new OA\Items(ref: '#/components/schemas/HrSelfServiceTrainingRecord')),
        new OA\Property(property: 'certifications', type: 'array', items: new OA\Items(ref: '#/components/schemas/HrSelfServiceCertification')),
    ])]
)]
#[OA\Schema(
    schema: 'HrEmployeeDocument',
    description: 'Metadata of a personnel file document; the storage path is never exposed',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_type', type: 'string'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'original_name', type: 'string'),
        new OA\Property(property: 'mime_type', type: 'string'),
        new OA\Property(property: 'size_bytes', type: 'integer'),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'expiry_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'retention_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'contains_health_data', type: 'boolean'),
        new OA\Property(property: 'is_confidential', type: 'boolean'),
        new OA\Property(property: 'visible_to_employee', type: 'boolean'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HrEmployeeDocumentListResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HrEmployeeDocument'))]
)]
#[OA\Schema(
    schema: 'HrTrainingCourseRequest',
    description: 'There is no server-side validation on this endpoint: course_code and title are required in practice (an empty string is stored otherwise), the other fields fall back to the defaults shown. is_active is always true on creation.',
    type: 'object',
    properties: [
        new OA\Property(property: 'course_code', type: 'string', example: 'SAF-001', description: 'Code of the course, used to name the certification issued on completion (CERT-<code>)'),
        new OA\Property(property: 'title', type: 'string', example: 'Workplace safety'),
        new OA\Property(property: 'category', type: 'string', default: 'safety'),
        new OA\Property(property: 'provider', type: 'string', default: 'Enterprise Academy'),
        new OA\Property(property: 'duration_hours', type: 'number', default: 8),
        new OA\Property(property: 'cost_per_participant', type: 'number', default: 0, description: 'Cost of one participant, in the currency of the company'),
        new OA\Property(property: 'validity_months', type: 'integer', default: 24, description: 'Months a certification issued on completion stays valid'),
        new OA\Property(property: 'is_mandatory_compliance', type: 'boolean', default: false),
    ]
)]
#[OA\Schema(
    schema: 'HrCompleteTrainingRequest',
    type: 'object',
    properties: [new OA\Property(property: 'score', type: 'number', default: 100, description: 'Final score; 100 when omitted. Not range-checked by the server. Completing issues the certification of the course (valid validity_months from today).')]
)]
#[OA\Schema(
    schema: 'HrScanExpiringCertificationsRequest',
    type: 'object',
    properties: [new OA\Property(property: 'within_days', type: 'integer', default: 30, description: 'Certifications expiring on or before today plus this many days are alerted (already expired ones included); 30 when omitted')]
)]
#[OA\Schema(
    schema: 'HrScanExpiringCertificationsResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'expiring_certifications_alerted', type: 'integer', description: 'Number of certifications whose status changed or that had no alert yet')])]
)]
#[OA\Schema(
    schema: 'HrAssignSuccessorRequest',
    description: 'The server does not validate this body: successor_employee_id is required in practice and must be an employee of the active company',
    type: 'object',
    required: ['successor_employee_id'],
    properties: [
        new OA\Property(property: 'successor_employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'readiness_level', type: 'string', enum: ['ready_now', 'ready_1_2_years', 'developing'], default: 'ready_1_2_years', description: 'Stored as given; the documented values come from the position model'),
    ]
)]
#[OA\Schema(
    schema: 'HrPayrollForecastRequest',
    type: 'object',
    properties: [new OA\Property(property: 'period_code', type: 'string', example: '2026-11', description: 'Period the forecast is published for, YYYY-MM; the current month when omitted. Not validated.')]
)]
#[OA\Schema(
    schema: 'HrPayrollForecastResult',
    description: 'Forecast computed from the active employees (statuses onboarding, probation, active, promoted, transferred, rehired). It also publishes a payroll forecast event and, when the outflow is positive, an accrual event.',
    type: 'object',
    properties: [
        new OA\Property(property: 'period_code', type: 'string', example: '2026-11'),
        new OA\Property(property: 'active_headcount', type: 'integer'),
        new OA\Property(property: 'estimated_payroll_outflow', type: 'number', description: 'JSON number: a twelfth of the annual base salaries with the employer on-cost factor of the forecast'),
        new OA\Property(property: 'accrual_amount', type: 'number', description: 'JSON number: monthly provision for the 13th salary and severance'),
    ]
)]
#[OA\Schema(
    schema: 'HrPayrollForecastResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HrPayrollForecastResult')]
)]
#[OA\Schema(
    schema: 'HrCompleteProbationRequest',
    type: 'object',
    properties: [new OA\Property(property: 'confirmation_date', type: 'string', format: 'date', nullable: true, description: 'Date of the confirmation (Y-m-d); today when omitted. Not validated. The employee becomes active and a probation_passed lifecycle event is recorded.')]
)]
final class HumanResourcesTypedResponseSchemas {}
