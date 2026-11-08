<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

/**
 * Path parameter of the deprecated recurring billing API.
 */
#[OA\Parameter(parameter: 'LegacySubscriptionId', name: 'id', description: 'Identifier of the subscription', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class LegacySubscriptionParameters {}
