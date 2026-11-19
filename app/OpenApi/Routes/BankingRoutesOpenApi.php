<?php

declare(strict_types=1);

namespace App\OpenApi\Routes;

use OpenApi\Attributes as OA;

/**
 * OpenAPI operations of the `banking` routes that are not described elsewhere: method, path, parameters and the standard responses. The payloads are
 * described by the FormRequest or the validation of each controller action.
 */
#[OA\Post(path: '/api/v1/banking/reconciliation/transactions/{lineId}/match', operationId: 'postBankingReconciliationTransactionsLineIdMatch', description: 'BankStatementController::manualMatch', summary: 'Manual match', requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), parameters: [new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], tags: ['Banking'], responses: [new OA\Response(response: 200, description: 'Success', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
final class BankingRoutesOpenApi {}
