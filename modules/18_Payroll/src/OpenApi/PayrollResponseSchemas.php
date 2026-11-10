<?php

declare(strict_types=1);

namespace Modules\Payroll\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the payroll endpoints that return PayrollRecordResource: the real shape is the Laravel resource
 * (a bare `{data: ...}` object, or `{data: [...], links, meta}` for paginated lists), not the `{success, message, data}`
 * envelope. Decimal columns are decimal strings; rows without a tenant (tenant_id null, is_generic true) are shared by
 * every company and read-only.
 */
#[OA\Schema(
    schema: 'PayrollPageLinks',
    description: 'Links of a paginated Laravel resource collection',
    type: 'object',
    properties: [
        new OA\Property(property: 'first', type: 'string', nullable: true),
        new OA\Property(property: 'last', type: 'string', nullable: true),
        new OA\Property(property: 'prev', type: 'string', nullable: true),
        new OA\Property(property: 'next', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'PayrollPageMeta',
    description: 'Meta of a paginated Laravel resource collection',
    type: 'object',
    properties: [
        new OA\Property(property: 'current_page', type: 'integer'),
        new OA\Property(property: 'from', type: 'integer', nullable: true),
        new OA\Property(property: 'last_page', type: 'integer'),
        new OA\Property(property: 'links', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'url', type: 'string', nullable: true), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'page', type: 'integer', nullable: true), new OA\Property(property: 'active', type: 'boolean')], type: 'object')),
        new OA\Property(property: 'path', type: 'string'),
        new OA\Property(property: 'per_page', type: 'integer'),
        new OA\Property(property: 'to', type: 'integer', nullable: true),
        new OA\Property(property: 'total', type: 'integer'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollRunEventResource',
    description: 'Entry of the immutable history of a run (calculated, approved, approval revoked, finalized, reopened). Has no updated_at.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payroll_run_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'action', type: 'string', enum: ['Calculated', 'Approved', 'ApprovalRevoked', 'Finalized', 'Reopened']),
        new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Mandatory for ApprovalRevoked and Reopened'),
        new OA\Property(property: 'totals', type: 'object', nullable: true, description: 'Run totals at that moment: gross_salary, employee_deductions, net_salary, payout_amount, employer_contributions (decimal strings)'),
        new OA\Property(property: 'actor_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'actor_name', type: 'string'),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayslipLineResource',
    description: 'A line of a payslip',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payslip_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'wage_type_code', type: 'string', description: 'Code of the wage type', example: '1000'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'kind', type: 'string', enum: ['Earning', 'NonCash', 'Expense', 'Deduction', 'Employer', 'Information']),
        new OA\Property(property: 'quantity', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'rate', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'amount', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'certificate_box', type: 'string', nullable: true, description: 'Box of the Swiss salary certificate the line feeds'),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayslipResource',
    description: 'Payslip of an employee for a month. `lines` only on the detail (GET /payroll/payslips/{id}).',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payroll_run_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'employee_name', type: 'string'),
        new OA\Property(property: 'paid_days', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'gross_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'ahv_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'alv_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'uvg_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'ktg_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'bvg_insured_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'withholding_tax_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'withholding_tax_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'withholding_tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'withholding_tax_canton', type: 'string', nullable: true),
        new OA\Property(property: 'employee_deductions', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'net_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'payout_amount', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'employer_contributions', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'warnings', type: 'string', nullable: true, description: 'Free text of the engine (null when none)'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayslipLineResource')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollRunResource',
    description: 'Monthly payroll run. `payslips` is loaded by calculate and by the detail (GET /payroll/runs/{id}); `events` only by the detail. Status values are capitalised as stored.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'country', type: 'string', description: 'Country of the engine (CH, IT)', example: 'CH'),
        new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
        new OA\Property(property: 'status', type: 'string', enum: ['Draft', 'Calculated', 'Approved', 'Final'], description: 'Draft (calculable) to Calculated to Approved to Final (closed, immutable); reopen goes back to Calculated (approval revoked) or Draft (closed run)'),
        new OA\Property(property: 'payment_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'calculated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'finalized_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'finalized_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reopened_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'reopened_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'reopen_reason', type: 'string', nullable: true),
        new OA\Property(property: 'reopen_count', type: 'integer'),
        new OA\Property(property: 'payslips', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayslipResource')),
        new OA\Property(property: 'events', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollRunEventResource')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollSalaryCertificateResource',
    description: 'Swiss salary certificate (form 11) of an employee and year; boxes are decimal strings',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'period_from', type: 'string', format: 'date'),
        new OA\Property(property: 'period_to', type: 'string', format: 'date'),
        new OA\Property(property: 'box1_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box2_benefits', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box3_irregular', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box4_capital', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box7_other', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box8_gross', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box9_social_contributions', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box10_pension', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box11_net', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box12_withholding_tax', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'box13_expenses', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'remarks', type: 'string', nullable: true),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCollectiveAgreementLevelResource',
    description: 'Level of a collective agreement with its monthly minimum',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'agreement_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'level', type: 'string', example: '4'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'monthly_minimum', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCollectiveAgreementResource',
    description: 'Collective agreement (CCNL). `levels` on the list, the detail and after update; not on create.',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'weekly_hours', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'installments', type: 'integer', description: 'Salary installments per year'),
        new OA\Property(property: 'fourteenth_month', type: 'integer', description: 'Month (1-12) in which the fourteenth salary is paid (default 7)'),
        new OA\Property(property: 'employer_inps_rate_percent', type: 'string', description: 'Decimal string.', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'levels', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollCollectiveAgreementLevelResource')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollWageTypeResource',
    description: 'Wage type with its contribution and tax flags',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
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
        new OA\Property(property: 'is_system', type: 'boolean'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollFamilyAllowanceRateResource',
    description: 'Family allowance rates of a canton from a date',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'canton', type: 'string', example: 'TI'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
        new OA\Property(property: 'child_allowance', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'education_allowance', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollInputResource',
    description: 'Variable input (overtime, bonus, expense...) of an employee for a month',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'wage_type_code', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'rate', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'amount', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollAbsenceResource',
    description: 'Absence relevant to the payroll',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'absence_type', type: 'string', enum: ['Sickness', 'Accident', 'Maternity', 'Paternity', 'Military', 'Vacation', 'Unpaid', 'Other']),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'percent', type: 'string', description: 'Percentage of incapacity or absence. Decimal string.'),
        new OA\Property(property: 'note', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeChildResource',
    description: 'Child of an employee (decrypted names) for the family allowances',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'first_name', type: 'string'),
        new OA\Property(property: 'last_name', type: 'string', nullable: true),
        new OA\Property(property: 'date_of_birth', type: 'string', format: 'date'),
        new OA\Property(property: 'in_education', type: 'boolean'),
        new OA\Property(property: 'education_end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'allowance_eligible', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeProfileResource',
    description: 'Payroll profile of an employee with the decrypted sensitive fields; decimals are decimal strings',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'social_security_number', type: 'string', nullable: true),
        new OA\Property(property: 'iban', type: 'string', nullable: true),
        new OA\Property(property: 'street', type: 'string', nullable: true),
        new OA\Property(property: 'house_number', type: 'string', nullable: true),
        new OA\Property(property: 'postal_code', type: 'string', nullable: true),
        new OA\Property(property: 'city', type: 'string', nullable: true),
        new OA\Property(property: 'gender', type: 'string', nullable: true),
        new OA\Property(property: 'residence_permit', type: 'string', nullable: true),
        new OA\Property(property: 'residence_country', type: 'string'),
        new OA\Property(property: 'residence_canton', type: 'string', nullable: true),
        new OA\Property(property: 'work_canton', type: 'string', nullable: true),
        new OA\Property(property: 'civil_status', type: 'string', nullable: true),
        new OA\Property(property: 'church_tax', type: 'boolean'),
        new OA\Property(property: 'pay_type', type: 'string', enum: ['monthly', 'hourly']),
        new OA\Property(property: 'salary_installments_per_year', type: 'integer'),
        new OA\Property(property: 'hourly_wage', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'weekly_hours', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'holiday_weeks', type: 'integer'),
        new OA\Property(property: 'is_fixed_term_contract', type: 'boolean'),
        new OA\Property(property: 'is_withholding_tax_subject', type: 'boolean'),
        new OA\Property(property: 'withholding_tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'withholding_tax_rate_percent', type: 'string', description: 'Decimal string.', nullable: true),
        new OA\Property(property: 'non_occupational_accident_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'occupational_accident_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'daily_sickness_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'family_allowance_fund_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'pension_fund_employee_rate_percent', type: 'string', description: 'Decimal string.', nullable: true),
        new OA\Property(property: 'regional_surcharge_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'municipal_surcharge_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'collective_agreement_code', type: 'string', nullable: true),
        new OA\Property(property: 'contract_level', type: 'string', nullable: true),
        new OA\Property(property: 'inps_qualification', type: 'string', nullable: true),
        new OA\Property(property: 'region_code', type: 'string', nullable: true),
        new OA\Property(property: 'municipality_code', type: 'string', nullable: true),
        new OA\Property(property: 'birth_place', type: 'string', nullable: true),
        new OA\Property(property: 'italian_tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'frontier_regime', type: 'string', nullable: true),
        new OA\Property(property: 'frontier_status_valid_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'frontier_commune_in_zone', type: 'boolean', nullable: true),
        new OA\Property(property: 'frontier_returns_daily', type: 'boolean'),
        new OA\Property(property: 'ccl_zone', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_generic', type: 'boolean', description: 'True for the shared rows without a tenant (read-only for the companies)'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollRunEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollRunResource')]
)]
#[OA\Schema(
    schema: 'PayrollRunPage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollRunResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayslipEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayslipResource')]
)]
#[OA\Schema(
    schema: 'PayslipPage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayslipResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollSalaryCertificateEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollSalaryCertificateResource')]
)]
#[OA\Schema(
    schema: 'PayrollSalaryCertificatePage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollSalaryCertificateResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCollectiveAgreementEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollCollectiveAgreementResource')]
)]
#[OA\Schema(
    schema: 'PayrollCollectiveAgreementPage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollCollectiveAgreementResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCollectiveAgreementLevelEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollCollectiveAgreementLevelResource')]
)]
#[OA\Schema(
    schema: 'PayrollWageTypeEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollWageTypeResource')]
)]
#[OA\Schema(
    schema: 'PayrollWageTypePage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollWageTypeResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollFamilyAllowanceRateEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollFamilyAllowanceRateResource')]
)]
#[OA\Schema(
    schema: 'PayrollFamilyAllowanceRatePage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollFamilyAllowanceRateResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollInputEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollInputResource')]
)]
#[OA\Schema(
    schema: 'PayrollInputPage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollInputResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollAbsenceEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollAbsenceResource')]
)]
#[OA\Schema(
    schema: 'PayrollAbsencePage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollAbsenceResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeChildEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollEmployeeChildResource')]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeChildPage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollEmployeeChildResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeProfileEnvelope',
    description: 'Bare Laravel resource envelope `{data: ...}` (no success/message)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollEmployeeProfileResource')]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeProfilePage',
    description: 'Paginated Laravel resource collection `{data: [...], links, meta}` (no success/message)',
    type: 'object',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollEmployeeProfileResource')),
        new OA\Property(property: 'links', ref: '#/components/schemas/PayrollPageLinks'),
        new OA\Property(property: 'meta', ref: '#/components/schemas/PayrollPageMeta'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollEmployeeProfileNullableEnvelope',
    description: 'Envelope `{data: profile|null}`: data is null when the employee has no payroll profile yet',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollEmployeeProfileResource', nullable: true)]
)]
#[OA\Schema(
    schema: 'PayrollSimulatedLine',
    description: 'Line of a simulated payslip (nothing is saved)',
    type: 'object',
    properties: [
        new OA\Property(property: 'wage_type_code', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'kind', type: 'string', enum: ['Earning', 'NonCash', 'Expense', 'Deduction', 'Employer', 'Information']),
        new OA\Property(property: 'quantity', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'rate', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'amount', type: 'string', description: 'Decimal string.'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollSimulatedPayslip',
    description: 'Payslip computed by the engine without saving it',
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_name', type: 'string'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'paid_days', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'gross_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'ahv_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'alv_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'uvg_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'ktg_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'bvg_insured_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'withholding_tax_basis', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'withholding_tax_rate_percent', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'withholding_tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'withholding_tax_canton', type: 'string', nullable: true),
        new OA\Property(property: 'employee_deductions', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'net_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'payout_amount', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'employer_contributions', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'warnings', type: 'string', nullable: true),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollSimulatedLine')),
    ]
)]
#[OA\Schema(
    schema: 'PayrollSimulationResponse',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollSimulatedPayslip'))]
)]
#[OA\Schema(
    schema: 'PayrollPreviewResult',
    description: 'Quick estimate of the monthly pay of an employee',
    type: 'object',
    properties: [
        new OA\Property(property: 'country_code', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'gross_monthly_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'total_deductions', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'net_monthly_salary', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'employer_total_cost', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'deduction_breakdown', type: 'object', description: 'Deduction name => amount (decimal strings)'),
        new OA\Property(property: 'compliance_notes', type: 'string'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollPreviewResponse',
    description: 'Envelope `{data: preview}`',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollPreviewResult')]
)]
#[OA\Schema(
    schema: 'PayrollCostAllocationLine',
    description: 'Labour cost share of an employee allocated to a target',
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_name', type: 'string'),
        new OA\Property(property: 'target_type', type: 'string', enum: ['project', 'cost_center', 'unallocated']),
        new OA\Property(property: 'target_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'hours', type: 'string', description: 'Decimal string.'),
        new OA\Property(property: 'amount', type: 'string', description: 'Decimal string.'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCostAllocationTotal',
    description: 'Total allocated to a target',
    type: 'object',
    properties: [
        new OA\Property(property: 'target_type', type: 'string', enum: ['project', 'cost_center', 'unallocated']),
        new OA\Property(property: 'target_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'amount', type: 'string', description: 'Decimal string.'),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCostAllocation',
    description: 'Allocation of the labour cost of a run',
    type: 'object',
    properties: [
        new OA\Property(property: 'run_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollCostAllocationLine')),
        new OA\Property(property: 'totals', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollCostAllocationTotal')),
    ]
)]
#[OA\Schema(
    schema: 'PayrollCostAllocationResponse',
    description: 'Envelope `{data: allocation}`',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollCostAllocation')]
)]
#[OA\Schema(
    schema: 'PayrollYearEndEmployee',
    description: 'Year totals of an employee from the closed runs',
    type: 'object',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_name', type: 'string'),
        new OA\Property(property: 'months', type: 'array', description: 'Months (1-12) with a closed payslip', items: new OA\Items(type: 'integer')),
        new OA\Property(property: 'gross_salary', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'employee_deductions', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'net_salary', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'payout_amount', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'employer_contributions', type: 'string', description: 'Decimal string'),
        new OA\Property(property: 'amounts_by_code', type: 'object', description: 'Wage type code => total amount (decimal strings), sorted by code', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    ]
)]
#[OA\Schema(
    schema: 'PayrollYearEndSummary',
    type: 'object',
    properties: [
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'employees', type: 'array', items: new OA\Items(ref: '#/components/schemas/PayrollYearEndEmployee')),
    ]
)]
#[OA\Schema(
    schema: 'PayrollYearEndResponse',
    description: 'Envelope `{data: summary}` (format=json)',
    type: 'object',
    properties: [new OA\Property(property: 'data', ref: '#/components/schemas/PayrollYearEndSummary')]
)]
#[OA\Schema(
    schema: 'PayrollGeneratedCertificates',
    type: 'object',
    properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'generated', type: 'integer', description: 'Number of certificates generated')])]
)]
final class PayrollResponseSchemas {}
