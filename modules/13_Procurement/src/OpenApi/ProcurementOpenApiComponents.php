<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the enterprise endpoints of the procurement module.
 */
#[OA\Parameter(parameter: 'ProcRecordId', name: 'id', description: 'Identifier of the record', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ProcPage', name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))]
#[OA\Parameter(parameter: 'ProcPerPage', name: 'per_page', description: 'Rows per page (at most 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1))]
#[OA\Parameter(parameter: 'ProcStatusFilter', name: 'status', description: 'Filter by status', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'ProcDateFrom', name: 'from', description: 'First day of the period (default: first day of the year)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'ProcDateTo', name: 'to', description: 'Last day of the period (default: today)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Response(response: 'ProcUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ProcForbidden', description: 'The role of the user in the company cannot write in the procurement module', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ProcNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ProcUnprocessable', description: 'Invalid data, or the state of the documents does not allow the operation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class ProcurementOpenApiComponents {}
