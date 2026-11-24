<?php

declare(strict_types=1);

namespace Modules\Manufacturing\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the endpoints of the manufacturing module.
 */
#[OA\Parameter(parameter: 'MfgId', name: 'id', description: 'Identifier of the record in the active company', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'MfgOperationId', name: 'operationId', description: 'Identifier of the production order operation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'MfgPage', name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))]
#[OA\Parameter(parameter: 'MfgPerPage', name: 'per_page', description: 'Rows per page (at most 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1))]
#[OA\Parameter(parameter: 'MfgStatus', name: 'status', description: 'Filter by status', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'MfgItemFilter', name: 'mfg_item_id', description: 'Filter by manufacturing item', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'MfgFrom', name: 'from', description: 'First day of the window', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'MfgTo', name: 'to', description: 'Last day of the window (at most 366 days after from)', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Response(response: 'MfgUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'MfgForbidden', description: 'The role of the user in the company cannot write in the manufacturing module, or the action needs a manufacturing manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'MfgNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'MfgUnprocessable', description: 'Invalid data, or the state of the record (status, open quantity, missing stock, no open fiscal period) does not allow the operation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class ManufacturingOpenApiComponents {}
