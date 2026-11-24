<?php

declare(strict_types=1);

namespace Modules\Childcare\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'ChildcareId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class ChildcareOpenApiComponents {}
