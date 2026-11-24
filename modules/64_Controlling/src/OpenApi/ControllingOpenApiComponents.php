<?php

declare(strict_types=1);

namespace Modules\Controlling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters and responses, apart from the operations (swagger-php drops the schemas of a class that declares them).
 */
#[OA\Parameter(parameter: 'ControllingId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'ControllingUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ControllingForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'ControllingUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class ControllingOpenApiComponents {}
