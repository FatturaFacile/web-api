<?php

declare(strict_types=1);

namespace Modules\Controlling\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters, apart from the operations (swagger-php drops the schemas of a class that declares them).
 */
#[OA\Parameter(parameter: 'ControllingId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class ControllingOpenApiComponents {}
