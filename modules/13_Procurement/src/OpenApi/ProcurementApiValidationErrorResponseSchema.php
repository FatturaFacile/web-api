<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProcurementApiValidationErrorResponse',
    title: 'Procurement API Validation Error Response',
    description: 'Validation errors dictionary',
    required: ['message', 'errors']
)]
class ProcurementApiValidationErrorResponseSchema
{
    #[OA\Property(example: 'The given data was invalid.')]
    public string $message;

    #[OA\Property(type: 'object')]
    public array $errors;
}
