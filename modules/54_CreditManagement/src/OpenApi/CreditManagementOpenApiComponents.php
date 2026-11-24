<?php

declare(strict_types=1);

namespace Modules\CreditManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'CreditId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'CreditCustomerId', name: 'customerId', description: 'Customer (contact) identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class CreditManagementOpenApiComponents {}
