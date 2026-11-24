<?php

declare(strict_types=1);

namespace Modules\Workshop\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads and responses of the labour time and vehicle history endpoints of the workshop.
 */
#[OA\Schema(schema: 'WorkshopTimeStartRequest', type: 'object', properties: [
    new OA\Property(property: 'technician_user_id', description: 'Defaults to the caller', type: 'string', format: 'uuid'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopTimeEntryRequest', type: 'object', required: ['technician_user_id', 'started_at', 'minutes'], properties: [
    new OA\Property(property: 'technician_user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time', example: '2026-11-09T08:00:00+01:00'),
    new OA\Property(property: 'minutes', type: 'integer', example: 90),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'WorkshopTimeBillRequest', type: 'object', required: ['hourly_rate', 'tax_rate'], properties: [
    new OA\Property(property: 'hourly_rate', description: 'Labour rate per hour, chosen by the user', type: 'string', example: '120.00'),
    new OA\Property(property: 'tax_rate', description: 'Chosen by the user', type: 'string', example: '8.10'),
    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Mano d\'opera officina'),
])]
#[OA\Schema(schema: 'WorkshopTimeSummaryResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'entries', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'technicians', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'technician_user_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'minutes', type: 'integer'),
            new OA\Property(property: 'hours', type: 'string'),
            new OA\Property(property: 'unbilled_minutes', type: 'integer'),
        ])),
    ]),
])]
#[OA\Schema(schema: 'WorkshopVehicleHistoryResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'folder_number', type: 'string'),
        new OA\Property(property: 'kind', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'mileage_in', type: 'integer', nullable: true),
        new OA\Property(property: 'mileage_out', type: 'integer', nullable: true),
        new OA\Property(property: 'complaint', type: 'string', nullable: true),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'net_total', type: 'string'),
    ])),
])]
final class WorkshopLabourOpenApi {}
