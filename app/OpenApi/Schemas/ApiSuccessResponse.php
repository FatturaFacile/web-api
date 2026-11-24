<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApiSuccessResponse',
    description: 'Standard successful JSON envelope',
    properties: [
        new OA\Property(property: 'success', type: 'boolean', example: true),
        new OA\Property(property: 'message', type: 'string', example: 'Operation completed successfully.'),
        new OA\Property(property: 'data', type: 'object', nullable: true),
    ]
)]
class ApiSuccessResponse {}
