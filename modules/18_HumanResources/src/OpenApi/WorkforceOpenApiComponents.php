<?php

declare(strict_types=1);

namespace Modules\HumanResources\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared query parameters of shift planning. They live apart from the schemas: swagger-php drops every schema of a class
 * that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'HumanResourcesFrom', name: 'from', description: 'First local day', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'HumanResourcesTo', name: 'to', description: 'Last local day', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
final class WorkforceOpenApiComponents {}
