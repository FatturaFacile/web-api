<?php

declare(strict_types=1);

namespace App\OpenApi\Routes;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations of the `wms` routes: method, path, parameters and the standard responses. The payloads are
 * described by the FormRequest of each controller action.
 */
#[OA\Post(path: '/api/v1/wms/transfer-discrepancies/{id}/resolve', operationId: 'postWmsTransferDiscrepanciesResolveResolveDiscrepancy', description: 'StockTransferController::resolveDiscrepancy', summary: 'Resolve Discrepancy (Resolve)', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], tags: ['Wms'], responses: [new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
#[OA\Get(path: '/api/v1/wms/consignment/valuation', operationId: 'getWmsConsignmentValuationValuation', description: 'ConsignmentController::valuation', summary: 'Valuation (Valuation)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Wms'], responses: [new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
#[OA\Get(path: '/api/v1/wms/consignment/consumption-reports', operationId: 'getWmsConsignmentConsumptionReportsDeclarations', description: 'ConsignmentController::declarations', summary: 'Declarations (Consumption Reports)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Wms'], responses: [new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
#[OA\Post(path: '/api/v1/wms/consignment/consumption-reports', operationId: 'postWmsConsignmentConsumptionReportsDeclare', description: 'ConsignmentController::declare', summary: 'Declare (Consumption Reports)', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), tags: ['Wms'], responses: [new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
final class WmsRoutesOpenApi {}
