<?php

declare(strict_types=1);

namespace Modules\Projects\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters and responses of the Projects module. They live apart from the schemas: swagger-php drops
 * every schema of a class that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'ProjectsId', name: 'id', description: 'Project identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsTaskId', name: 'taskId', description: 'Task identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsDependencyId', name: 'dependencyId', description: 'Task dependency identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsAssignmentId', name: 'assignmentId', description: 'Task assignment identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsMilestoneId', name: 'milestoneId', description: 'Milestone identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsTimesheetId', name: 'timesheetId', description: 'Timesheet identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsExpenseId', name: 'expenseId', description: 'Identifier of the expense, that is the `id` of the cost entry (ProjectsCostEntry) returned by POST /projects/{id}/expenses and listed by GET /projects/{id}/costs with cost_source_type project_expense. The id of another kind of cost entry (timesheet, supplier invoice, manual cost) answers 404.', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsChangeOrderId', name: 'orderId', description: 'Change order identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsRateCardId', name: 'rateCardId', description: 'Rate card identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsUserId', name: 'userId', description: 'User identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProjectsPerPage', name: 'per_page', description: 'Items per page (max 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100, minimum: 1))]
#[OA\Parameter(parameter: 'ProjectsPage', name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
#[OA\Response(response: 'ProjectsUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ProjectsForbidden', description: 'The role of the user does not allow the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ProjectsNotFound', description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ProjectsConflict', description: 'The record is not in a state that allows the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ProjectsValidationError', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class ProjectsOpenApiComponents {}
