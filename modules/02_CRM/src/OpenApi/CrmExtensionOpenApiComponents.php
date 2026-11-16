<?php

declare(strict_types=1);

namespace Modules\CRM\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters of the CRM extension. They live apart from the schemas: swagger-php drops every schema of a
 * class that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'CrmExtId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class CrmExtensionOpenApiComponents {}
