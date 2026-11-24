<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the contract, leave management, self-service, overtime, closing and document
 * endpoints of Human Resources. Schemas live in HumanResourcesExtensionSchemas: swagger-php drops the schemas of a
 * class that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'HrRecordId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'HrEmployeeFilter', name: 'employee_id', description: 'Only the records of this employee', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'HrUserFilter', name: 'user_id', description: 'Only the records of this user (the employee account)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'HrPeriodFrom', name: 'from', description: 'First day (inclusive)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'HrPeriodTo', name: 'to', description: 'Last day (inclusive)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'HrYearFilter', name: 'year', description: 'Only this year', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 2026))]
#[OA\Response(response: 'HrUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'HrForbidden', description: 'The role has no access to the HR module (read-only roles never write); self-service routes need an employee record linked to the user', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'HrNotFound', description: 'Record not found in the active company (or not an own record on self-service routes)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'HrConflict', description: 'The record is not in a state that allows the action (decided, under workflow, or in a closed month)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'HrUnprocessable', description: 'Validation error or HR rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class HumanResourcesOpenApiComponents {}
