<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TwoFactorPasswordRequest',
    description: 'The current password, asked again before a change to the two-factor setup.',
    required: ['current_password'],
    properties: [
        new OA\Property(property: 'current_password', type: 'string', format: 'password'),
    ]
)]
class TwoFactorPasswordRequest {}
