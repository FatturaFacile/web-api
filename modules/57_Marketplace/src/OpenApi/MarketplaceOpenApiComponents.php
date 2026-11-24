<?php

declare(strict_types=1);

namespace Modules\Marketplace\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'MarketplaceId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'MarketplaceConnectorKey', name: 'key', description: 'Connector key of the catalog', in: 'path', required: true, schema: new OA\Schema(type: 'string', pattern: '^[a-z][a-z0-9-]*$'))]
final class MarketplaceOpenApiComponents {}
