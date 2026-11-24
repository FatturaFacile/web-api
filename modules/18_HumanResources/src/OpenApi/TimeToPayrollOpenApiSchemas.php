<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses of the transfer of the monthly working time to the payroll variable inputs and of the ArG Art. 17b night
 * work compensation balances. Fields identifying employees (employee_id, user_id) are personal data.
 */
#[OA\Schema(schema: 'HrTimeToPayrollInput', description: 'Payroll variable input generated or updated by the transfer', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'wage_type_code', description: '1001 hourly, 1010 overtime 125%, 1020 night supplement 25%, 1030 Sunday and public holiday 50%', type: 'string', example: '1010'),
    new OA\Property(property: 'quantity', description: 'Hours', type: 'number'),
    new OA\Property(property: 'rate', description: 'Hourly rate or supplement rate applied', type: 'number'),
    new OA\Property(property: 'amount', type: 'number'),
    new OA\Property(property: 'note', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HrNightCompensationEvaluation', description: 'ArG Art. 17b evaluation of the night work of the period: regular night workers (25 or more night shifts in the year) get 10% compensatory rest time, the others a 25% cash supplement', type: 'object', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'ytd_night_shifts', type: 'integer'),
    new OA\Property(property: 'is_regular_night_worker', type: 'boolean'),
    new OA\Property(property: 'compensation_mode', type: 'string', enum: ['cash_25_percent', 'time_10_percent']),
    new OA\Property(property: 'period_night_minutes', type: 'integer'),
    new OA\Property(property: 'time_compensation_minutes', type: 'integer'),
    new OA\Property(property: 'cash_supplement_minutes', type: 'integer'),
    new OA\Property(property: 'ytd_accrued_rest_minutes', type: 'integer'),
    new OA\Property(property: 'ytd_taken_rest_minutes', type: 'integer'),
    new OA\Property(property: 'ytd_remaining_rest_minutes', type: 'integer'),
])]
#[OA\Schema(schema: 'HrTimeToPayrollItem', description: 'Working time of one employee in the month and the payroll inputs derived from it', type: 'object', properties: [
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'worked_minutes', type: 'integer'),
    new OA\Property(property: 'overtime_minutes', type: 'integer'),
    new OA\Property(property: 'night_minutes', type: 'integer'),
    new OA\Property(property: 'sunday_holiday_minutes', type: 'integer'),
    new OA\Property(property: 'night_compensation', ref: '#/components/schemas/HrNightCompensationEvaluation'),
    new OA\Property(property: 'payroll_inputs', type: 'array', items: new OA\Items(ref: '#/components/schemas/HrTimeToPayrollInput')),
])]
#[OA\Schema(schema: 'HrTimeToPayrollResult', description: 'Outcome of the transfer', type: 'object', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'month', type: 'integer'),
    new OA\Property(property: 'employees_processed', description: 'Employees with working time, overtime, night or Sunday work in the month', type: 'integer'),
    new OA\Property(property: 'inputs_upserted', type: 'integer'),
    new OA\Property(property: 'night_time_bank_credits', description: 'Regular night workers whose night work was credited as compensatory rest time instead of cash', type: 'integer'),
    new OA\Property(property: 'items', description: 'Only the employees that were processed', type: 'array', items: new OA\Items(ref: '#/components/schemas/HrTimeToPayrollItem')),
])]
#[OA\Schema(schema: 'HrTimeToPayrollResponse', type: 'object', properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/HrTimeToPayrollResult'),
])]
#[OA\Schema(schema: 'HrNightWorkBalance', description: 'Night work compensation bank of an employee in a calendar year (ArG Art. 17b al. 2, OLL 1 Art. 31)', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'year', type: 'integer', example: 2026),
    new OA\Property(property: 'ytd_night_shifts', type: 'integer'),
    new OA\Property(property: 'is_regular_night_worker', description: 'True from 25 night shifts in the year', type: 'boolean'),
    new OA\Property(property: 'total_night_minutes', type: 'integer'),
    new OA\Property(property: 'accrued_rest_minutes', description: '10% compensatory rest time accrued', type: 'integer'),
    new OA\Property(property: 'taken_rest_minutes', type: 'integer'),
    new OA\Property(property: 'remaining_rest_minutes', type: 'integer'),
    new OA\Property(property: 'cash_supplement_minutes', description: 'Minutes paid with the 25% cash supplement', type: 'integer'),
    new OA\Property(property: 'compensation_mode', type: 'string', enum: ['cash_25_percent', 'time_10_percent']),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HrNightWorkBalanceListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', description: 'Ordered by night shifts of the year, highest first', items: new OA\Items(ref: '#/components/schemas/HrNightWorkBalance')),
])]
final class TimeToPayrollOpenApiSchemas {}
