<?php

declare(strict_types=1);

namespace Modules\Payroll\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Requests and responses of the Swissdec status, hiring simulation, payslip links and payment batches.
 */
#[OA\Schema(schema: 'PayrollSwissdecStatus', description: 'Certification status: the application is not certified', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'is_certified', type: 'boolean', example: false),
        new OA\Property(property: 'structure_basis', type: 'string'),
        new OA\Property(property: 'schema_validated', type: 'boolean', example: false),
        new OA\Property(property: 'current_certification_basis', type: 'string'),
        new OA\Property(property: 'domains_built', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'domains_not_built', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'missing_for_certification', type: 'array', items: new OA\Items(type: 'string')),
    ]),
])]
#[OA\Schema(schema: 'PayrollSimulateHiringRequest', description: 'Request payload validated by SimulateHiringRequest', required: ['year'], type: 'object', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'annual_gross', type: 'number', nullable: true),
    new OA\Property(property: 'target_net_monthly', type: 'number', nullable: true),
    new OA\Property(property: 'date_of_birth', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'fte_ratio', type: 'number', nullable: true),
    new OA\Property(property: 'salary_installments_per_year', type: 'integer', nullable: true),
    new OA\Property(property: 'withholding_tax_rate_percent', type: 'number', nullable: true),
    new OA\Property(property: 'pension_fund_employee_rate_percent', type: 'number', nullable: true),
    new OA\Property(property: 'residence_country', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PayrollHiringSimulation', description: 'An estimate before hiring', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'direction', type: 'string', enum: ['gross_to_net', 'net_to_gross']),
        new OA\Property(property: 'annual_gross', type: 'string'),
        new OA\Property(property: 'monthly_gross', type: 'string'),
        new OA\Property(property: 'monthly_net', type: 'string'),
        new OA\Property(property: 'is_exact', description: 'False when no salary reaches the wanted net', type: 'boolean'),
        new OA\Property(property: 'employer_total_cost', type: 'string'),
        new OA\Property(property: 'deductions', type: 'object'),
        new OA\Property(property: 'notes', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'PayrollOpenPayslipLinkRequest', description: 'Request payload validated by OpenPayslipLinkRequest', required: ['birth_date'], type: 'object', properties: [
    new OA\Property(property: 'birth_date', type: 'string', format: 'date'),
])]
#[OA\Schema(schema: 'PayrollCreatePaymentsRequest', description: 'Request payload validated by CreatePayrollPaymentsRequest', required: ['allocations'], type: 'object', properties: [
    new OA\Property(property: 'allocations', type: 'array', items: new OA\Items(required: ['bank_account_id'], properties: [
        new OA\Property(property: 'bank_account_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'employee_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
        new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    ], type: 'object')),
])]
final class PayrollExtrasSchemas {}
