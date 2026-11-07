<?php

declare(strict_types=1);

namespace Modules\Payroll\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Requests and responses of short-time work, bad weather and construction wages.
 */
#[OA\Schema(schema: 'PayrollSaveShortTimeCaseRequest', description: 'Request payload validated by SaveShortTimeCaseRequest', required: ['kind', 'cause', 'starts_on'], type: 'object', properties: [
    new OA\Property(property: 'kind', type: 'string', enum: ['short_time', 'bad_weather']),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'cause', type: 'string', maxLength: 2000),
    new OA\Property(property: 'notified_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'is_urgent', type: 'boolean'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'authority_reference', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PayrollCalculateShortTimeClaimRequest', description: 'Request payload validated by CalculateShortTimeClaimRequest', required: ['year', 'month', 'employees'], type: 'object', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'month', type: 'integer'),
    new OA\Property(property: 'employees', type: 'array', items: new OA\Items(required: ['employee_id'], properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'target_hours', type: 'number', nullable: true),
        new OA\Property(property: 'worked_hours', type: 'number', nullable: true),
        new OA\Property(property: 'absence_hours', type: 'number', nullable: true),
        new OA\Property(property: 'overtime_hours', type: 'number', nullable: true),
        new OA\Property(property: 'regular_allowances', type: 'number', nullable: true),
        new OA\Property(property: 'consented', type: 'boolean'),
    ], type: 'object')),
])]
#[OA\Schema(schema: 'PayrollRecordReimbursementRequest', description: 'Request payload validated by RecordReimbursementRequest', required: ['amount'], type: 'object', properties: [
    new OA\Property(property: 'amount', type: 'number', minimum: 0),
    new OA\Property(property: 'paid_on', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'PayrollShortTimeClaim', description: 'A monthly reimbursement request', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'submitted', 'reimbursed']),
        new OA\Property(property: 'loss_percent', type: 'string'),
        new OA\Property(property: 'meets_minimum_loss', type: 'boolean'),
        new OA\Property(property: 'total_compensation', type: 'string'),
        new OA\Property(property: 'claim_due_on', type: 'string', format: 'date'),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'lines', type: 'array', items: new OA\Items(type: 'object')),
    ]),
])]
#[OA\Schema(schema: 'PayrollSaveConstructionParametersRequest', description: 'Request payload validated by SaveConstructionParametersRequest', required: ['agreement_code', 'valid_from', 'annual_hours', 'accounting_year_start_month', 'minimum_wages', 'source'], type: 'object', properties: [
    new OA\Property(property: 'agreement_code', type: 'string', maxLength: 30),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'annual_hours', type: 'number'),
    new OA\Property(property: 'accounting_year_start_month', type: 'integer', minimum: 1, maximum: 12),
    new OA\Property(property: 'minimum_wages', description: 'qualification > zone > {monthly, hourly}', type: 'object'),
    new OA\Property(property: 'source', type: 'string', maxLength: 2000),
])]
#[OA\Schema(schema: 'PayrollSaveWorkCalendarRequest', description: 'Request payload validated by SaveWorkCalendarRequest', required: ['agreement_code', 'months'], type: 'object', properties: [
    new OA\Property(property: 'agreement_code', type: 'string', maxLength: 30),
    new OA\Property(property: 'months', type: 'array', items: new OA\Items(required: ['year', 'month', 'planned_hours'], properties: [
        new OA\Property(property: 'year', type: 'integer'),
        new OA\Property(property: 'month', type: 'integer'),
        new OA\Property(property: 'planned_hours', type: 'number'),
    ], type: 'object')),
])]
#[OA\Schema(schema: 'PayrollJointCommissionReport', description: 'Annual report for the joint commission', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'agreement_code', type: 'string'),
        new OA\Property(property: 'accounting_year', type: 'integer'),
        new OA\Property(property: 'annual_hours', type: 'string'),
        new OA\Property(property: 'source', type: 'string'),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'qualification', type: 'string'),
            new OA\Property(property: 'zone', type: 'string'),
            new OA\Property(property: 'headcount', type: 'integer'),
            new OA\Property(property: 'worked_hours', type: 'string'),
            new OA\Property(property: 'gross_salary', type: 'string'),
            new OA\Property(property: 'average_hourly', type: 'string', nullable: true),
            new OA\Property(property: 'average_monthly', type: 'string', nullable: true),
            new OA\Property(property: 'below_minimum', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        ])),
    ]),
])]
final class ShortTimeOpenApiSchemas {}
