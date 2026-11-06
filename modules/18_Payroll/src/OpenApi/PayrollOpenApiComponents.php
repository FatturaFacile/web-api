<?php

declare(strict_types=1);

namespace Modules\Payroll\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the payroll endpoints.
 */
#[OA\Parameter(parameter: 'PayrollRecordId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'PayrollLevelId', name: 'levelId', description: 'Agreement level identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'PayrollEmployeeId', name: 'employeeId', description: 'Employee identifier (hr_employees)', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'PayrollEmployeeFilter', name: 'employee_id', description: 'Only the records of this employee', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'PayrollYearFilter', name: 'year', description: 'Only this year', in: 'query', required: false, schema: new OA\Schema(type: 'integer', example: 2026))]
#[OA\Parameter(parameter: 'PayrollMonthFilter', name: 'month', description: 'Only this month', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 12, minimum: 1))]
#[OA\Parameter(parameter: 'PayrollYearRequired', name: 'year', description: 'The year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026))]
#[OA\Parameter(parameter: 'PayrollMonthRequired', name: 'month', description: 'The month', in: 'query', required: true, schema: new OA\Schema(type: 'integer', maximum: 12, minimum: 1))]
#[OA\Response(response: 'PayrollUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PayrollForbidden', description: 'Only owners, administrators and users with a payroll permission on the company, or the employee for the own documents', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PayrollNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PayrollConflict', description: 'The run of the period is closed: reopen it first', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PayrollUnprocessable', description: 'Validation error or payroll rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class PayrollOpenApiComponents {}
