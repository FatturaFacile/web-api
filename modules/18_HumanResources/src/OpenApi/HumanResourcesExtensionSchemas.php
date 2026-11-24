<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request bodies of the contract, leave management, self-service, overtime, closing and document endpoints.
 */
#[OA\Schema(
    schema: 'HrSalaryComponentRequest',
    required: ['wage_type_code', 'description', 'amount'],
    properties: [
        new OA\Property(property: 'wage_type_code', description: 'Payroll wage type code the component is paid with', type: 'string', maxLength: 20, example: '1100'),
        new OA\Property(property: 'description', type: 'string', maxLength: 150, example: 'Superminimo'),
        new OA\Property(property: 'amount', description: 'Decimal string, two decimals', type: 'string', example: '150.00'),
        new OA\Property(property: 'frequency', type: 'string', enum: ['monthly', 'annual']),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HrContractStoreRequest',
    required: ['contract_type', 'job_title', 'start_date', 'weekly_hours', 'base_annual_salary'],
    properties: [
        new OA\Property(property: 'contract_number', type: 'string', maxLength: 60),
        new OA\Property(property: 'contract_type', type: 'string', enum: ['permanent', 'fixed_term', 'apprenticeship', 'contractor', 'internship', 'seasonal']),
        new OA\Property(property: 'employment_type', type: 'string', enum: ['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal']),
        new OA\Property(property: 'job_title', type: 'string', maxLength: 150),
        new OA\Property(property: 'job_grade_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'collective_agreement', description: 'Free label of the agreement', type: 'string', nullable: true),
        new OA\Property(property: 'collective_agreement_id', description: 'Agreement of the payroll tables (company or generic row)', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'collective_agreement_level_id', description: 'Level of that agreement', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'level_code', type: 'string', nullable: true),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'probation_end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'weekly_hours', type: 'string', example: '40.00'),
        new OA\Property(property: 'fte_percentage', type: 'string', example: '100.00'),
        new OA\Property(property: 'base_annual_salary', type: 'string', example: '52000.00'),
        new OA\Property(property: 'hourly_rate', type: 'string'),
        new OA\Property(property: 'loaded_hourly_cost', type: 'string'),
        new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
        new OA\Property(property: 'terms', type: 'object', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'salary_components', type: 'array', items: new OA\Items(ref: '#/components/schemas/HrSalaryComponentRequest')),
    ]
)]
#[OA\Schema(
    schema: 'HrContractUpdateRequest',
    properties: [
        new OA\Property(property: 'job_title', type: 'string'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'probation_end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'collective_agreement', type: 'string', nullable: true),
        new OA\Property(property: 'collective_agreement_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'collective_agreement_level_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'level_code', type: 'string', nullable: true),
        new OA\Property(property: 'terms', type: 'object', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HrLeaveTypeRequest',
    required: ['code', 'name', 'category', 'annual_entitlement_hours'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 60),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'category', type: 'string', enum: ['vacation', 'sick', 'maternity', 'paternity', 'parental', 'unpaid', 'rol', 'study', 'bereavement', 'military', 'accident', 'other']),
        new OA\Property(property: 'is_paid', type: 'boolean'),
        new OA\Property(property: 'requires_approval', type: 'boolean'),
        new OA\Property(property: 'requires_attachment', type: 'boolean'),
        new OA\Property(property: 'annual_entitlement_hours', type: 'string', example: '160.00'),
        new OA\Property(property: 'accrual_frequency', type: 'string', enum: ['monthly', 'yearly', 'none']),
        new OA\Property(property: 'max_carryover_hours', type: 'string'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'HrLeaveAccrualPolicyRequest',
    required: ['leave_type_id', 'code', 'name', 'annual_entitlement_hours', 'valid_from'],
    properties: [
        new OA\Property(property: 'leave_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', maxLength: 40),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'employment_type', type: 'string', nullable: true, enum: ['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal']),
        new OA\Property(property: 'annual_entitlement_hours', description: 'Company data: the application ships no statutory entitlement', type: 'string', example: '160.00'),
        new OA\Property(property: 'accrual_frequency', type: 'string', enum: ['monthly', 'yearly']),
        new OA\Property(property: 'prorate_by_fte', type: 'boolean'),
        new OA\Property(property: 'max_balance_hours', type: 'string', nullable: true),
        new OA\Property(property: 'max_carryover_hours', type: 'string', nullable: true),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'source_reference', description: 'Contract clause, agreement article or law the entitlement comes from', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HrReasonRequest',
    required: ['reason'],
    properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 1000, minLength: 3)]
)]
#[OA\Schema(
    schema: 'HrOwnLeaveRequest',
    required: ['leave_type_id', 'start_date', 'end_date', 'total_hours'],
    properties: [
        new OA\Property(property: 'leave_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'total_hours', type: 'string', example: '16.00'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'medical_certificate_ref', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HrOvertimeRuleRequest',
    required: ['code', 'name', 'basis', 'threshold_minutes', 'premium_percent'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 40),
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'basis', type: 'string', enum: ['daily', 'weekly']),
        new OA\Property(property: 'threshold_minutes', type: 'integer', example: 480),
        new OA\Property(property: 'premium_percent', type: 'string', example: '25.00'),
        new OA\Property(property: 'wage_type_code', type: 'string', nullable: true),
        new OA\Property(property: 'priority', type: 'integer'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'source_reference', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'HrTimeClosingRequest',
    required: ['year', 'month'],
    properties: [
        new OA\Property(property: 'year', type: 'integer', example: 2026),
        new OA\Property(property: 'month', type: 'integer', maximum: 12, minimum: 1),
    ]
)]
#[OA\Schema(
    schema: 'HrEmployeeDocumentUpload',
    required: ['file', 'document_type', 'title'],
    properties: [
        new OA\Property(property: 'file', type: 'string', format: 'binary'),
        new OA\Property(property: 'document_type', type: 'string', enum: ['contract', 'amendment', 'id_card', 'work_permit', 'medical_fitness', 'certificate', 'training', 'nda', 'warning', 'payslip', 'other']),
        new OA\Property(property: 'title', type: 'string', maxLength: 200),
        new OA\Property(property: 'issue_date', type: 'string', format: 'date'),
        new OA\Property(property: 'expiry_date', type: 'string', format: 'date'),
        new OA\Property(property: 'retention_until', description: 'After this day file and row are erased', type: 'string', format: 'date'),
        new OA\Property(property: 'contains_health_data', type: 'boolean'),
        new OA\Property(property: 'is_confidential', type: 'boolean'),
        new OA\Property(property: 'visible_to_employee', type: 'boolean'),
    ]
)]
final class HumanResourcesExtensionSchemas {}
