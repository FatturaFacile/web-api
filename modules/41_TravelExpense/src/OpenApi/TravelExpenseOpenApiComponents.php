<?php

declare(strict_types=1);

namespace Modules\TravelExpense\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'TravelId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'TravelItemId', name: 'itemId', description: 'Identifier of the leg, claim or expense of the trip', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class TravelExpenseOpenApiComponents {}
