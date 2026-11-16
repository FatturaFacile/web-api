<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'BiId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'BiDatasetKey', name: 'dataset', description: 'Dataset key of the semantic layer', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[a-z][a-z0-9_]*$'))]
final class BiOpenApiComponents {}
