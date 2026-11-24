<?php

declare(strict_types=1);

namespace App\OpenApi\Components;

use OpenApi\Attributes as OA;

/**
 * Shared OpenAPI parameters of the operational privacy endpoints (breach operations). Kept apart from the schemas:
 * swagger-php drops the schemas of a class that also declares parameters.
 */
#[OA\Parameter(parameter: 'PrivacyBreachId', name: 'id', description: 'Identifier of the personal data breach of the active company', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class PrivacyOperationsOpenApiComponents {}
