<?php

declare(strict_types=1);

namespace Modules\AssetManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the endpoints of the asset management module.
 */
#[OA\Parameter(parameter: 'EamAssetId', name: 'id', description: 'Identifier of the asset', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'EamCategoryId', name: 'id', description: 'Identifier of the asset category', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'EamLocationId', name: 'id', description: 'Identifier of the asset location', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'EamComponentId', name: 'componentId', description: 'Identifier of the component', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'EamRunId', name: 'id', description: 'Identifier of the depreciation run', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'EamPlanId', name: 'id', description: 'Identifier of the maintenance plan', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'EamWorkOrderId', name: 'id', description: 'Identifier of the maintenance work order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'EamPage', name: 'page', description: 'Page number', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1))]
#[OA\Parameter(parameter: 'EamPerPage', name: 'per_page', description: 'Rows per page (at most 100)', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1))]
#[OA\Parameter(parameter: 'EamLedgerType', name: 'ledger_type', description: 'Book: statutory (civil, default), tax (fiscal), ifrs, management; civil and fiscal are aliases', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['statutory', 'tax', 'ifrs', 'management', 'civil', 'fiscal']))]
#[OA\Response(response: 'EamUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'EamForbidden', description: 'The role of the user in the company cannot write in the asset module, or the record is locked by an approval workflow', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'EamNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'EamUnprocessable', description: 'Invalid data, or the state of the record (disposed, already depreciated, no open fiscal period) does not allow the operation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class AssetManagementOpenApiComponents {}
