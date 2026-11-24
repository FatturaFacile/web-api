<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Requests and responses of schedule groups, payroll view, reader import and meal bookings.
 */
#[OA\Schema(schema: 'HumanResourcesSaveScheduleGroupRequest', description: 'Request payload validated by SaveScheduleGroupRequest', required: ['name'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HumanResourcesSaveScheduleRuleRequest', description: 'Request payload validated by SaveScheduleRuleRequest', required: ['valid_from'], type: 'object', properties: [
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'tolerance_before_minutes', type: 'integer', minimum: 0, maximum: 120),
    new OA\Property(property: 'tolerance_after_minutes', type: 'integer', minimum: 0, maximum: 120),
    new OA\Property(property: 'rounding_minutes', type: 'integer', minimum: 0, maximum: 60),
    new OA\Property(property: 'rounding_mode', type: 'string', enum: ['none', 'nearest', 'employee_favour', 'employer_favour']),
    new OA\Property(property: 'night_allowance_percent', type: 'number', nullable: true),
    new OA\Property(property: 'sunday_allowance_percent', type: 'number', nullable: true),
    new OA\Property(property: 'holiday_allowance_percent', type: 'number', nullable: true),
    new OA\Property(property: 'daily_allowance_amount', type: 'number', nullable: true),
    new OA\Property(property: 'daily_allowance_minimum_minutes', type: 'integer'),
])]
#[OA\Schema(schema: 'HumanResourcesAssignScheduleGroupRequest', description: 'Request payload validated by AssignScheduleGroupRequest', required: ['employee_id', 'valid_from'], type: 'object', properties: [
    new OA\Property(property: 'employee_id', description: 'User id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesSaveTimeBadgeRequest', description: 'Request payload validated by SaveTimeBadgeRequest', required: ['employee_id', 'badge_code'], type: 'object', properties: [
    new OA\Property(property: 'employee_id', description: 'User id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'badge_code', type: 'string', maxLength: 120),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HumanResourcesImportTimePunchesRequest', description: 'Request payload validated by ImportTimePunchesRequest', required: ['reader_id', 'punches'], type: 'object', properties: [
    new OA\Property(property: 'reader_id', type: 'string', maxLength: 120),
    new OA\Property(property: 'source', type: 'string', enum: ['badge', 'terminal']),
    new OA\Property(property: 'punches', type: 'array', items: new OA\Items(required: ['external_id', 'occurred_at', 'punch_type'], properties: [
        new OA\Property(property: 'external_id', type: 'string'),
        new OA\Property(property: 'badge_code', type: 'string', nullable: true),
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'punch_type', type: 'string', enum: ['In', 'Out', 'BreakStart', 'BreakEnd', 'Meal']),
        new OA\Property(property: 'meal_type', type: 'string', nullable: true),
    ], type: 'object')),
])]
#[OA\Schema(schema: 'HumanResourcesStoreMealBookingRequest', description: 'Request payload validated by StoreMealBookingRequest', required: ['meal_type'], type: 'object', properties: [
    new OA\Property(property: 'employee_id', description: 'User id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'meal_type', type: 'string', maxLength: 30),
    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'source', type: 'string', nullable: true),
    new OA\Property(property: 'note', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesPayrollTimeView', description: 'Recorded minutes and what the payroll derives from them', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'days', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'recorded_minutes', description: 'The real minutes', type: 'integer'),
            new OA\Property(property: 'payroll_minutes', description: 'After tolerance and rounding', type: 'integer'),
            new OA\Property(property: 'adjustment_minutes', type: 'integer'),
            new OA\Property(property: 'night_allowance_minutes', type: 'integer'),
            new OA\Property(property: 'sunday_allowance_minutes', type: 'integer'),
            new OA\Property(property: 'holiday_allowance_minutes', type: 'integer'),
            new OA\Property(property: 'daily_allowance_amount', type: 'string'),
        ])),
        new OA\Property(property: 'totals', type: 'object'),
    ]),
])]
final class TimeClockOpenApiSchemas {}
