<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApiErrorResponse',
    description: 'Standard error JSON envelope',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: false),
        new OA\Property(property: 'message', type: 'string', example: 'The requested resource was not found.'),
        new OA\Property(property: 'error_code', type: 'string', example: 'RESOURCE_NOT_FOUND', nullable: true),
    ]
)]
class ApiErrorResponse {}
