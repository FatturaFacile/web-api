<?php

declare(strict_types=1);

namespace Modules\Contracts\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the lifecycle, obligation, party and document endpoints of contracts.
 */
#[OA\Parameter(parameter: 'ContractKindPath', name: 'kind', description: 'Customer contracts are the service contracts, supplier contracts the procurement framework agreements', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['customer', 'supplier']))]
#[OA\Parameter(parameter: 'ContractIdPath', name: 'id', description: 'Identifier of the contract', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'ContractObligationIdPath', name: 'obligationId', description: 'Identifier of the obligation or milestone', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'ContractUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ContractForbidden', description: 'The role of the user in the company cannot use the contract register (or this action)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ContractNotFound', description: 'Not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ContractConflict', description: 'The state of the contract does not allow the operation', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ContractUnprocessable', description: 'Invalid data', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class ContractLifecycleSchemas {}
