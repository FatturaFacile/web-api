<?php

declare(strict_types=1);

namespace Modules\Procurement\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ProcurementApiErrorResponse',
    title: 'Procurement API Error Response',
    description: 'Standard error envelope for procurement endpoints',
    required: ['success', 'message']
)]
class ProcurementApiErrorResponseSchema
{
    #[OA\Property(example: false)]
    public bool $success;

    #[OA\Property(example: 'Resource not found or unauthorized')]
    public string $message;

    #[OA\Property(example: 401)]
    public int $status;
}
