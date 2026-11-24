<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'HumanResources',
    description: 'Enterprise Human Resources & Workforce Management endpoints (Employees, Organization, Leave, Time, Performance, Training)'
)]
#[OA\Schema(
    schema: 'EmployeeResource',
    title: 'Employee Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_number', type: 'string', example: 'EMP-2026-0001'),
        new OA\Property(property: 'first_name', type: 'string', example: 'Marco'),
        new OA\Property(property: 'last_name', type: 'string', example: 'Rossi'),
        new OA\Property(property: 'full_name', type: 'string', example: 'Marco Rossi'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'marco.rossi@example.com'),
        new OA\Property(property: 'status', type: 'string', enum: ['candidate', 'onboarding', 'probation', 'active', 'promoted', 'transferred', 'on_leave', 'terminated', 'retired']),
        new OA\Property(property: 'employment_type', type: 'string', enum: ['full_time', 'part_time', 'contractor', 'fixed_term', 'intern']),
        new OA\Property(property: 'hire_date', type: 'string', format: 'date'),
        new OA\Property(property: 'base_salary', type: 'number', format: 'double', example: 55000.00),
        new OA\Property(property: 'hourly_cost_rate', type: 'number', format: 'double', example: 42.50),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
    ]
)]
#[OA\Schema(
    schema: 'LeaveRequestResource',
    title: 'Leave Request Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'request_number', type: 'string', example: 'LR-202606-0001'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'leave_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'days_requested', type: 'number', format: 'double', example: 5.0),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'approved', 'rejected', 'cancelled']),
    ]
)]
#[OA\Tag(
    name: 'HumanResources Swiss Payroll',
    description: 'Swissdec-style Swiss payroll: rate sets, wage types, withholding tax tariffs, payroll runs, payslips and ELM-style salary declarations (not Swissdec certified). Requires the hr.payroll.view / hr.payroll.manage permissions.'
)]
#[OA\Schema(
    schema: 'SwissPayrollRunResource',
    title: 'Swiss Payroll Run Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'run_number', type: 'string', example: 'PR-202611-001'),
        new OA\Property(property: 'period', type: 'string', example: '2026-11'),
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
        new OA\Property(property: 'payment_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'calculated', 'approved', 'closed']),
        new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
        new OA\Property(property: 'employee_count', type: 'integer', example: 12),
        new OA\Property(property: 'totals', properties: [
            new OA\Property(property: 'gross', type: 'string', example: '96000.00'),
            new OA\Property(property: 'employee_deductions', type: 'string', example: '10989.00'),
            new OA\Property(property: 'employer_contributions', type: 'string', example: '11061.00'),
            new OA\Property(property: 'withholding_tax', type: 'string', example: '760.00'),
            new OA\Property(property: 'net', type: 'string', example: '85011.00'),
        ], type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'SwissPayslipResource',
    title: 'Swiss Payslip Resource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'payroll_run_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'days_worked', type: 'integer', example: 30),
        new OA\Property(property: 'gross', type: 'string', example: '8000.00'),
        new OA\Property(property: 'employee_deductions', type: 'string', example: '915.75'),
        new OA\Property(property: 'employer_contributions', type: 'string', example: '921.75'),
        new OA\Property(property: 'net', type: 'string', example: '7084.25'),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'code', type: 'string', example: 'AHV_IV_EO'),
            new OA\Property(property: 'line_type', type: 'string', enum: ['earning', 'contribution', 'withholding_tax']),
            new OA\Property(property: 'side', type: 'string', enum: ['employee', 'employer']),
            new OA\Property(property: 'base', type: 'string', example: '8000.00'),
            new OA\Property(property: 'rate_percent', type: 'string', example: '5.3', nullable: true),
            new OA\Property(property: 'amount', type: 'string', example: '424.00'),
        ], type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'SwissPayrollProfileResource',
    title: 'Swiss Payroll Profile Resource',
    properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'ahv_number_masked', type: 'string', example: '756.****.****.85', nullable: true),
        new OA\Property(property: 'work_canton', type: 'string', example: 'TI'),
        new OA\Property(property: 'permit_type', type: 'string', example: 'B', nullable: true),
        new OA\Property(property: 'qst_tariff_code', type: 'string', example: 'A0N', nullable: true),
        new OA\Property(property: 'church_tax', type: 'boolean'),
        new OA\Property(property: 'uvg_class', type: 'string', example: 'A1', nullable: true),
        new OA\Property(property: 'ktg_insured', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'SwissSalaryDeclarationResource',
    title: 'Swiss Salary Declaration Resource (ELM-style XML)',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'declaration_number', type: 'string', example: 'ELM-2026-001'),
        new OA\Property(property: 'period_year', type: 'integer', example: 2026),
        new OA\Property(property: 'schema_version', type: 'string', example: '5.0'),
        new OA\Property(property: 'xsd_validated', type: 'boolean'),
        new OA\Property(property: 'xml_sha256', type: 'string', example: 'a3f1...'),
        new OA\Property(property: 'download_url', type: 'string', format: 'uri'),
    ]
)]
final class HumanResourcesOpenApiSchemas {}
