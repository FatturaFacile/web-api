<?php

declare(strict_types=1);

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * Operations of the human resources leave, timesheet and time tracking controllers, declared here to keep the
 * controllers themselves free of documentation attributes while the time tracking work is in progress.
 */
#[OA\Get(
    path: '/api/v1/hr/leave-types',
    operationId: 'getLeaveTypesHr',
    description: 'Returns the leave types of the active tenant ordered by code.',
    summary: 'List leave types',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Leave Types'],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesLeaveTypeModel'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/leave-balances',
    operationId: 'getLeaveBalancesHr',
    description: 'Returns the leave balances of the active tenant, optionally for a single employee.',
    summary: 'List leave balances',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Leave Balances'],
    parameters: [
        new OA\Parameter(name: 'employee_id', in: 'query', required: false, description: 'Filter by employee', schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesLeaveBalanceResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Post(
    path: '/api/v1/hr/leave-balances/accrue',
    operationId: 'runAccrualsHrLeaveBalancesAccrue',
    description: 'Runs the monthly leave accruals of a fiscal year and returns how many balances were accrued.',
    summary: 'Run leave accruals',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Leave Balances'],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'fiscal_year', type: 'integer')])),
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'balances_accrued', type: 'integer')])])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/leave-requests',
    operationId: 'getLeaveRequestsHr',
    description: 'Returns the leave requests of the active tenant, latest first.',
    summary: 'List leave requests',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Leave Requests'],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesLeaveRequestResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Post(
    path: '/api/v1/hr/leave-requests',
    operationId: 'submitLeaveHrLeaveRequests',
    description: 'Submits a leave request for an employee.',
    summary: 'Submit leave request',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Leave Requests'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSubmitLeaveRequestFormRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Leave request created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesLeaveRequestResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Post(
    path: '/api/v1/hr/leave-requests/{id}/approve',
    operationId: 'approveLeaveHrLeaveRequest',
    description: 'Approves a leave request on behalf of the authenticated user.',
    summary: 'Approve leave request',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Leave Requests'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the leave request', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesLeaveRequestResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Post(
    path: '/api/v1/hr/leave-requests/{id}/reject',
    operationId: 'rejectLeaveHrLeaveRequest',
    description: 'Rejects a leave request, recording the reason.',
    summary: 'Reject leave request',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Leave Requests'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the leave request', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', default: 'Rejected by manager')])),
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesLeaveRequestResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/timesheets',
    operationId: 'getTimesheetsHr',
    description: 'Returns the timesheets of the active tenant, latest work date first.',
    summary: 'List timesheets',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Timesheets'],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesTimesheetResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Post(
    path: '/api/v1/hr/timesheets',
    operationId: 'submitTimesheetHrTimesheets',
    description: 'Submits a timesheet for an employee.',
    summary: 'Submit timesheet',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Timesheets'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSubmitTimesheetRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Timesheet created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimesheetResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Post(
    path: '/api/v1/hr/timesheets/{id}/approve',
    operationId: 'approveTimesheetHrTimesheet',
    description: 'Approves a timesheet on behalf of the authenticated user.',
    summary: 'Approve timesheet',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Timesheets'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the timesheet', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimesheetResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/time/events',
    operationId: 'getEventsHrTime',
    description: 'Returns the time clock events of an employee between two local dates, optionally including deleted ones.',
    summary: 'List time clock events',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'include_deleted', in: 'query', required: false, description: 'Filter by include deleted', schema: new OA\Schema(type: 'boolean', nullable: true)),
        new OA\Parameter(name: 'employee_id', in: 'query', required: false, description: 'Filter by employee', schema: new OA\Schema(type: 'string', format: 'uuid', nullable: true)),
        new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Start of the period', schema: new OA\Schema(type: 'string', format: 'date', nullable: true)),
        new OA\Parameter(name: 'to', in: 'query', required: false, description: 'End of the period Must be on or after `from`.', schema: new OA\Schema(type: 'string', format: 'date', nullable: true)),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, nullable: true)),
        new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesTimeClockEventResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Post(
    path: '/api/v1/hr/time/events',
    operationId: 'storeEventHrTimeEvents',
    description: 'Records a time clock event for the authenticated user or for another employee.',
    summary: 'Record time clock event',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesStoreTimeEventRequest')),
    responses: [
        new OA\Response(response: 201, description: 'Event created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimeClockEventResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Patch(
    path: '/api/v1/hr/time/events/{id}',
    operationId: 'correctEventHrTimeEvents',
    description: 'Corrects a time clock event, keeping the audit trail.',
    summary: 'Correct time clock event',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the event', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesCorrectTimeEventRequest')),
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimeClockEventResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Delete(
    path: '/api/v1/hr/time/events/{id}',
    operationId: 'destroyEventHrTimeEvents',
    description: 'Deletes a time clock event, recording the reason.',
    summary: 'Delete time clock event',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the event', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimeClockEventResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Put(
    path: '/api/v1/hr/time/daily-records',
    operationId: 'saveDailyRecordHrTimeDailyRecords',
    description: 'Saves the daily time record of an employee.',
    summary: 'Save daily time record',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveTimeDailyRecordRequest')),
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimeDailyRecordResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/time/report',
    operationId: 'getReportHrTime',
    description: 'Evaluates the working time of an employee between two dates.',
    summary: 'Get working time report',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'employee_id', in: 'query', required: false, description: 'Filter by employee', schema: new OA\Schema(type: 'string', format: 'uuid', nullable: true)),
        new OA\Parameter(name: 'from', in: 'query', required: true, description: 'Start of the period', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, description: 'End of the period Must be on or after `from`.', schema: new OA\Schema(type: 'string', format: 'date')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'days'), new OA\Property(property: 'weeks'), new OA\Property(property: 'issues'), new OA\Property(property: 'employeeId', type: 'string'), new OA\Property(property: 'employeeName', type: 'string'), new OA\Property(property: 'recordingMode'), new OA\Property(property: 'from'), new OA\Property(property: 'to'), new OA\Property(property: 'statutoryOvertimeBeforeMinutes', type: 'integer'), new OA\Property(property: 'statutoryOvertimeCapMinutes', type: 'integer')])])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/time/audit',
    operationId: 'getAuditHrTime',
    description: 'Returns the time audit entries. Without the manage permission the list is limited to the authenticated user.',
    summary: 'List time audit entries',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'employee_id', in: 'query', required: false, description: 'Filter by employee', schema: new OA\Schema(type: 'string', format: 'uuid', nullable: true)),
        new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Start of the period', schema: new OA\Schema(type: 'string', format: 'date', nullable: true)),
        new OA\Parameter(name: 'to', in: 'query', required: false, description: 'End of the period Must be on or after `from`.', schema: new OA\Schema(type: 'string', format: 'date', nullable: true)),
        new OA\Parameter(name: 'include_deleted', in: 'query', required: false, description: 'Filter by include deleted', schema: new OA\Schema(type: 'boolean', nullable: true)),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, nullable: true)),
        new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/HumanResourcesTimeAuditEntryResource'))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/time/holidays',
    operationId: 'getHolidaysHrTime',
    description: 'Returns the public holidays of a canton for a year.',
    summary: 'List holidays',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'canton', in: 'query', required: false, description: 'Filter by canton', schema: new OA\Schema(type: 'string', nullable: true, enum: ['AG', 'AI', 'AR', 'BE', 'BL', 'BS', 'FR', 'GE', 'GL', 'GR', 'JU', 'LU', 'NE', 'NW', 'OW', 'SG', 'SH', 'SO', 'SZ', 'TG', 'TI', 'UR', 'VD', 'VS', 'ZG', 'ZH'])),
        new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Filter by year', schema: new OA\Schema(type: 'integer', minimum: 1900, maximum: 2200, nullable: true)),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'date'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'day_fraction', type: 'number')]))])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
#[OA\Get(
    path: '/api/v1/hr/time/profiles/{userId}',
    operationId: 'getProfileHrTimeProfiles',
    description: 'Returns the time recording profile of a user.',
    summary: 'Get time recording profile',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'userId', in: 'path', required: true, description: 'Identifier of the user', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimeRecordingProfileResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ]
)]
#[OA\Put(
    path: '/api/v1/hr/time/profiles/{userId}',
    operationId: 'saveProfileHrTimeProfiles',
    description: 'Saves the time recording profile of a user, recording the reason.',
    summary: 'Save time recording profile',
    security: [['bearerAuth' => [], 'tenantHeader' => []]],
    tags: ['HR Time'],
    parameters: [
        new OA\Parameter(name: 'userId', in: 'path', required: true, description: 'Identifier of the user', schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveTimeProfileRequest')),
    responses: [
        new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/HumanResourcesTimeRecordingProfileResource')])),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ]
)]
final class HumanResourcesTimePaths {}
