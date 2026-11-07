<?php

declare(strict_types=1);

namespace Modules\Visitors\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters. They live apart from the schemas: swagger-php drops every schema of a class that also
 * declares class-level parameters.
 */
#[OA\Parameter(parameter: 'VisitorsId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'VisitorsKioskKey', name: 'X-Kiosk-Key', description: 'Public key of the kiosk', in: 'header', required: true, schema: new OA\Schema(type: 'string'))]
#[OA\Parameter(parameter: 'VisitorsKioskSecret', name: 'X-Kiosk-Secret', description: 'Secret of the kiosk, shown once when it was registered', in: 'header', required: true, schema: new OA\Schema(type: 'string'))]
final class VisitorsOpenApiComponents {}
