<?php

declare(strict_types=1);

namespace Modules\Workshop\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the workshop scheduling endpoints (service bays and planned slots).
 */
#[OA\Schema(schema: 'WorkshopBayRequest', description: 'A service bay. On PUT every field is optional.', type: 'object', required: ['code', 'name'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 30),
    new OA\Property(property: 'name', type: 'string', maxLength: 120),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'WorkshopSlotRequest', description: 'A planned window of work. On PUT only the window, technician, bay and notes can change and every field is optional.', type: 'object', required: ['folder_id', 'starts_at', 'ends_at'], properties: [
    new OA\Property(property: 'folder_id', description: 'Open or in-progress folder; not accepted on PUT', type: 'string', format: 'uuid'),
    new OA\Property(property: 'technician_user_id', description: 'Member of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'bay_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'ends_at', description: 'After starts_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'notes', type: 'string', maxLength: 500, nullable: true),
])]
final class WorkshopSchedulingOpenApi {}
