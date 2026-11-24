<?php

declare(strict_types=1);

namespace Modules\Payroll\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the payroll endpoints, derived from their form requests.
 */
#[OA\Schema(
    schema: 'PayrollCalculateRequest',
    type: 'object',
    required: ['year', 'month'],
    properties: [
        new OA\Property(property: 'year', type: 'integer', example: 2026),
        new OA\Property(property: 'month', type: 'integer', maximum: 12, minimum: 1, example: 3),
        new OA\Property(property: 'payment_date', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollSimulateRequest',
    type: 'object',
    required: ['year', 'month'],
    properties: [
        new OA\Property(property: 'year', type: 'integer', example: 2026),
        new OA\Property(property: 'month', type: 'integer', maximum: 12, minimum: 1, example: 3),
        new OA\Property(property: 'employee_id', description: 'Only this employee', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollPreviewRequest',
    type: 'object',
    required: ['employee_id'],
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'target_month', description: 'Any day of the month, today when omitted', type: 'string', format: 'date', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollReopenRequest',
    type: 'object',
    required: ['reason'],
    properties: [new OA\Property(property: 'reason', description: 'Why the closed run is reopened', type: 'string', maxLength: 1000, minLength: 5)]
)]
#[OA\Schema(
    schema: 'PayrollSwissSettingsRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'ahv_employee_rate_percent', type: 'number'),
        new OA\Property(property: 'ahv_employer_rate_percent', type: 'number'),
        new OA\Property(property: 'ahv_administration_cost_percent', type: 'number'),
        new OA\Property(property: 'alv_employee_rate_percent', type: 'number'),
        new OA\Property(property: 'alv_employer_rate_percent', type: 'number'),
        new OA\Property(property: 'alv_annual_ceiling', type: 'number'),
        new OA\Property(property: 'uvg_annual_ceiling', type: 'number'),
        new OA\Property(property: 'nbu_employee_rate_percent', type: 'number'),
        new OA\Property(property: 'bu_employer_rate_percent', type: 'number'),
        new OA\Property(property: 'ktg_employee_rate_percent', type: 'number'),
        new OA\Property(property: 'ktg_employer_rate_percent', type: 'number'),
        new OA\Property(property: 'fak_employer_rate_percent', type: 'number'),
        new OA\Property(property: 'bvg_employer_share_percent', type: 'number', maximum: 100, minimum: 50),
        new OA\Property(property: 'bvg_risk_rate_percent', type: 'number'),
        new OA\Property(property: 'pensioner_allowance_monthly', type: 'number'),
        new OA\Property(property: 'default_work_canton', type: 'string', example: 'TI'),
        new OA\Property(property: 'ahv_member_number', type: 'string', nullable: true),
        new OA\Property(property: 'uvg_insurer', type: 'string', nullable: true),
        new OA\Property(property: 'bvg_insurer', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollItalianSettingsRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'inps_company_number', type: 'string', nullable: true),
        new OA\Property(property: 'inps_office_code', type: 'string', nullable: true),
        new OA\Property(property: 'inail_code', type: 'string', nullable: true),
        new OA\Property(property: 'employee_inps_rate_percent', type: 'number'),
        new OA\Property(property: 'employer_inps_rate_percent', type: 'number'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCollectiveAgreementRequest',
    type: 'object',
    required: ['code', 'name'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 30, example: 'H011'),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'weekly_hours', type: 'number'),
        new OA\Property(property: 'installments', type: 'integer', maximum: 14, minimum: 12),
        new OA\Property(property: 'fourteenth_month', type: 'integer', maximum: 12, minimum: 1),
        new OA\Property(property: 'employer_inps_rate_percent', type: 'number', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollAgreementLevelRequest',
    type: 'object',
    required: ['level', 'monthly_minimum'],
    properties: [
        new OA\Property(property: 'level', type: 'string', maxLength: 30),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'monthly_minimum', type: 'number', minimum: 0),
    ]
)]
#[OA\Schema(
    schema: 'PayrollWageTypeRequest',
    type: 'object',
    required: ['code', 'name', 'kind'],
    properties: [
        new OA\Property(property: 'code', type: 'string', maxLength: 6, pattern: '^[0-9A-Z]{1,6}$'),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'kind', type: 'string', enum: ['Earning', 'NonCash', 'Expense', 'Deduction', 'Employer', 'Information']),
        new OA\Property(property: 'subject_to_ahv', type: 'boolean'),
        new OA\Property(property: 'subject_to_alv', type: 'boolean'),
        new OA\Property(property: 'subject_to_uvg', type: 'boolean'),
        new OA\Property(property: 'subject_to_ktg', type: 'boolean'),
        new OA\Property(property: 'subject_to_bvg', type: 'boolean'),
        new OA\Property(property: 'subject_to_withholding_tax', type: 'boolean'),
        new OA\Property(property: 'is_irregular', type: 'boolean'),
        new OA\Property(property: 'certificate_box', type: 'string', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeProfileRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'social_security_number', description: 'AHV number or Italian tax code, stored encrypted', type: 'string', nullable: true),
        new OA\Property(property: 'iban', description: 'Stored encrypted', type: 'string', nullable: true),
        new OA\Property(property: 'street', description: 'Stored encrypted', type: 'string', nullable: true),
        new OA\Property(property: 'house_number', description: 'Stored encrypted', type: 'string', nullable: true),
        new OA\Property(property: 'postal_code', description: 'Stored encrypted', type: 'string', nullable: true),
        new OA\Property(property: 'city', description: 'Stored encrypted', type: 'string', nullable: true),
        new OA\Property(property: 'gender', type: 'string', enum: ['M', 'F'], nullable: true),
        new OA\Property(property: 'residence_permit', type: 'string', nullable: true),
        new OA\Property(property: 'residence_country', type: 'string', example: 'CH'),
        new OA\Property(property: 'residence_canton', type: 'string', nullable: true),
        new OA\Property(property: 'work_canton', type: 'string', nullable: true),
        new OA\Property(property: 'civil_status', type: 'string', nullable: true),
        new OA\Property(property: 'church_tax', type: 'boolean'),
        new OA\Property(property: 'pay_type', type: 'string', enum: ['monthly', 'hourly']),
        new OA\Property(property: 'salary_installments_per_year', type: 'integer', maximum: 14, minimum: 12),
        new OA\Property(property: 'hourly_wage', type: 'number'),
        new OA\Property(property: 'weekly_hours', type: 'number'),
        new OA\Property(property: 'holiday_weeks', type: 'integer'),
        new OA\Property(property: 'is_fixed_term_contract', type: 'boolean'),
        new OA\Property(property: 'is_withholding_tax_subject', type: 'boolean'),
        new OA\Property(property: 'withholding_tax_code', type: 'string', nullable: true, example: 'A0N'),
        new OA\Property(property: 'withholding_tax_rate_percent', description: 'Used when the tariff has not been imported', type: 'number', nullable: true),
        new OA\Property(property: 'non_occupational_accident_rate_percent', type: 'number'),
        new OA\Property(property: 'occupational_accident_rate_percent', type: 'number'),
        new OA\Property(property: 'daily_sickness_rate_percent', type: 'number'),
        new OA\Property(property: 'family_allowance_fund_rate_percent', type: 'number'),
        new OA\Property(property: 'pension_fund_employee_rate_percent', type: 'number', nullable: true),
        new OA\Property(property: 'regional_surcharge_rate_percent', type: 'number'),
        new OA\Property(property: 'municipal_surcharge_rate_percent', type: 'number'),
        new OA\Property(property: 'collective_agreement_code', type: 'string', nullable: true),
        new OA\Property(property: 'contract_level', type: 'string', nullable: true),
        new OA\Property(property: 'inps_qualification', type: 'string', nullable: true),
        new OA\Property(property: 'region_code', type: 'string', nullable: true),
        new OA\Property(property: 'municipality_code', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollInputRequest',
    type: 'object',
    required: ['employee_id', 'year', 'month', 'wage_type_code'],
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer', maximum: 12, minimum: 1),
        new OA\Property(property: 'wage_type_code', type: 'string', maxLength: 6),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'rate', type: 'number'),
        new OA\Property(property: 'amount', type: 'number'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollAbsenceRequest',
    type: 'object',
    required: ['employee_id', 'absence_type', 'start_date', 'end_date'],
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'absence_type', type: 'string', enum: ['Sickness', 'Accident', 'Maternity', 'Paternity', 'Military', 'Vacation', 'Unpaid', 'Other']),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'percent', type: 'number', maximum: 100, minimum: 1),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeChildRequest',
    type: 'object',
    required: ['employee_id', 'first_name', 'date_of_birth'],
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string', nullable: true),
        new OA\Property(property: 'date_of_birth', type: 'string', format: 'date'),
        new OA\Property(property: 'in_education', type: 'boolean'),
        new OA\Property(property: 'education_end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'allowance_eligible', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollFamilyAllowanceRateRequest',
    type: 'object',
    required: ['canton', 'valid_from', 'child_allowance', 'education_allowance'],
    properties: [
        new OA\Property(property: 'canton', type: 'string', example: 'TI'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'child_allowance', type: 'number', minimum: 215),
        new OA\Property(property: 'education_allowance', type: 'number', minimum: 268),
    ]
)]
#[OA\Schema(
    schema: 'PayrollAnnualParametersRequest',
    type: 'object',
    required: ['parameters'],
    properties: [
        new OA\Property(property: 'parameters', description: 'Values by snake_case key, as listed by the annual parameters of the country', type: 'object'),
        new OA\Property(property: 'legal_reference', type: 'string', nullable: true),
    ]
)]
final class PayrollRequestSchemas {}
