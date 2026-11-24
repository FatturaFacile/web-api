<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TenantResource',
    description: 'Tenant organization details',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'),
        new OA\Property(property: 'name', type: 'string', example: 'Acme Corp SA'),
        new OA\Property(property: 'tax_number', type: 'string', example: 'CHE-123.456.789'),
        new OA\Property(property: 'country', type: 'string', example: 'CH'),
        new OA\Property(property: 'currency', type: 'string', example: 'CHF'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-10-25T12:00:00Z'),
    ]
)]
class TenantResource {}
