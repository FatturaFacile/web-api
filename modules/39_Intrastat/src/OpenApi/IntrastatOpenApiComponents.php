<?php

declare(strict_types=1);

namespace Modules\Intrastat\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'IntrastatId', name: 'id', description: 'INTRA list identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'IntrastatLineId', name: 'lineId', description: 'Row identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'IntrastatProductId', name: 'productId', description: 'Product identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class IntrastatOpenApiComponents {}
