<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters, requests and records of shift planning and time clock details.
 */
#[OA\Schema(schema: 'HumanResourcesSaveShiftTypeRequest', description: 'Request payload validated by SaveShiftTypeRequest', required: ['code', 'name', 'category'], type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 20),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'category', type: 'string', enum: ['working', 'absence', 'on_call']),
    new OA\Property(property: 'start_time', type: 'string', example: '22:00', nullable: true),
    new OA\Property(property: 'end_time', type: 'string', example: '06:00', nullable: true),
    new OA\Property(property: 'break_minutes', type: 'integer', minimum: 0, maximum: 480),
    new OA\Property(property: 'is_paid_absence', type: 'boolean'),
    new OA\Property(property: 'color', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HumanResourcesShiftType', description: 'A shift type', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'category', type: 'string'),
    new OA\Property(property: 'start_time', type: 'string', nullable: true),
    new OA\Property(property: 'end_time', type: 'string', nullable: true),
    new OA\Property(property: 'break_minutes', type: 'integer'),
    new OA\Property(property: 'is_paid_absence', type: 'boolean'),
])]
#[OA\Schema(schema: 'HumanResourcesSaveShiftSequenceRequest', description: 'Request payload validated by SaveShiftSequenceRequest', required: ['name', 'pattern', 'steps'], type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'pattern', type: 'string', enum: ['rotation', 'weekly_without_sunday', 'weekly_without_weekend', 'manual']),
    new OA\Property(property: 'steps', description: 'Shift type ids, null for a free day', type: 'array', items: new OA\Items(type: 'string', format: 'uuid', nullable: true)),
    new OA\Property(property: 'skip_holidays', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HumanResourcesShiftSequence', description: 'A shift sequence', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'pattern', type: 'string'),
    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'string', nullable: true)),
    new OA\Property(property: 'skip_holidays', type: 'boolean'),
])]
#[OA\Schema(schema: 'HumanResourcesAssignShiftSequenceRequest', description: 'Request payload validated by AssignShiftSequenceRequest', required: ['employee_id', 'shift_sequence_id', 'start_date'], type: 'object', properties: [
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'shift_sequence_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
    new OA\Property(property: 'end_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'offset_days', type: 'integer', minimum: 0, maximum: 365),
])]
#[OA\Schema(schema: 'HumanResourcesSaveShiftExceptionRequest', description: 'Request payload validated by SaveShiftExceptionRequest', required: ['exception_date'], type: 'object', properties: [
    new OA\Property(property: 'exception_date', type: 'string', format: 'date'),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'shift_type_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reason', type: 'string', maxLength: 255, nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesSaveStaffingNeedRequest', description: 'Request payload validated by SaveStaffingNeedRequest', required: ['department_id', 'shift_type_id', 'day_kind', 'required_count', 'valid_from'], type: 'object', properties: [
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'shift_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'day_kind', type: 'string', enum: ['weekday', 'weekend', 'holiday']),
    new OA\Property(property: 'required_count', type: 'integer', minimum: 0, maximum: 1000),
    new OA\Property(property: 'valid_from', type: 'string', format: 'date'),
    new OA\Property(property: 'valid_to', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesSaveShiftRequest', description: 'Request payload validated by SaveShiftRequest', required: ['employee_id', 'shift_type_id', 'work_date'], type: 'object', properties: [
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'shift_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'work_date', type: 'string', format: 'date'),
    new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesShiftPeriodRequest', description: 'Request payload validated by ShiftPeriodRequest', required: ['from', 'to'], type: 'object', properties: [
    new OA\Property(property: 'from', type: 'string', format: 'date'),
    new OA\Property(property: 'to', type: 'string', format: 'date'),
    new OA\Property(property: 'department_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'employee_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'canton', type: 'string', nullable: true),
    new OA\Property(property: 'acknowledgement_reason', description: 'Confirms blocking findings at publication', type: 'string', maxLength: 1000, nullable: true),
])]
#[OA\Schema(schema: 'HumanResourcesShift', description: 'A shift of the plan', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'employee_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'shift_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'work_date', type: 'string', format: 'date'),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'break_minutes', type: 'integer'),
    new OA\Property(property: 'planned_minutes', type: 'integer'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'published']),
    new OA\Property(property: 'source', type: 'string', enum: ['manual', 'sequence', 'exception']),
])]
#[OA\Schema(schema: 'HumanResourcesShiftBoard', description: 'The shift board', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'days', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'coverage', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'violations', type: 'array', items: new OA\Items(type: 'object')),
    ]),
])]
final class WorkforceOpenApiSchemas {}
