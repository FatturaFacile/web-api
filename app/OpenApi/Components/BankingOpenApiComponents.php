<?php

declare(strict_types=1);

namespace App\OpenApi\Components;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters and responses of the banking endpoints (statements and reconciliation rules).
 */
#[OA\Parameter(parameter: 'BankingRuleId', name: 'ruleId', description: 'Reconciliation rule identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'BankingUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BankingForbidden', description: 'The role of the user does not allow the action', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BankingNotFound', description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BankingValidationError', description: 'Validation error, or a ledger account that is not configured', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class BankingOpenApiComponents {}
