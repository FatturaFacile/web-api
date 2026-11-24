<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ApiProblemDetailsResponse',
    description: 'RFC 7807 Problem Details envelope, served as application/problem+json',
    required: ['type', 'title', 'status', 'detail', 'instance'],
    properties: [
        new OA\Property(property: 'type', type: 'string', format: 'uri', example: 'https://httpstatuses.com/400'),
        new OA\Property(property: 'title', type: 'string', example: 'Missing Request Context'),
        new OA\Property(property: 'status', type: 'integer', example: 400),
        new OA\Property(property: 'detail', type: 'string', example: 'Every route requires tenant_id and user_id, as X-Tenant-Id and X-User-Id headers or as query parameters.'),
        new OA\Property(property: 'instance', type: 'string', example: '/api/v1/crm/leads'),
    ]
)]
class ApiProblemDetailsResponse {}
