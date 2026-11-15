<?php

declare(strict_types=1);

namespace Modules\Hospitality\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads and responses of the group booking, folio statement and availability grid endpoints.
 */
#[OA\Schema(schema: 'HospitalityGroupBlockRequest', description: 'A group block. On PUT every field is optional and the dates cannot change; rooms, when sent, is the full list of room types held.', type: 'object', required: ['name', 'check_in_date', 'check_out_date', 'release_date', 'rooms'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'organizer', type: 'string', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'check_in_date', type: 'string', format: 'date'),
    new OA\Property(property: 'check_out_date', description: 'After check_in_date', type: 'string', format: 'date'),
    new OA\Property(property: 'release_date', description: 'On or before check_in_date: the day the unpicked rooms go back on sale', type: 'string', format: 'date'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'rooms', type: 'array', items: new OA\Items(type: 'object', required: ['room_type_id', 'rooms_blocked'], properties: [
        new OA\Property(property: 'room_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'rooms_blocked', type: 'integer', minimum: 1),
        new OA\Property(property: 'nightly_rate', description: 'Rate negotiated for the group; empty uses the normal quote', type: 'number', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'HospitalityGroupBlockDatesRequest', type: 'object', required: ['check_in_date', 'check_out_date'], properties: [
    new OA\Property(property: 'check_in_date', type: 'string', format: 'date'),
    new OA\Property(property: 'check_out_date', description: 'After check_in_date', type: 'string', format: 'date'),
    new OA\Property(property: 'release_date', description: 'On or before check_in_date; empty keeps the current one', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityGroupStayRequest', type: 'object', required: ['guest_name', 'room_type_id', 'adults'], properties: [
    new OA\Property(property: 'guest_name', type: 'string', maxLength: 160),
    new OA\Property(property: 'guest_email', type: 'string', format: 'email', nullable: true),
    new OA\Property(property: 'room_type_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'rate_plan_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'check_in_date', description: 'Defaults to the check-in date of the block', type: 'string', format: 'date'),
    new OA\Property(property: 'check_out_date', description: 'Defaults to the check-out date of the block', type: 'string', format: 'date'),
    new OA\Property(property: 'adults', type: 'integer', minimum: 1),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityFolioInvoiceRequest', type: 'object', required: ['contact_id', 'tax_rate'], properties: [
    new OA\Property(property: 'contact_id', description: 'Customer billed', type: 'string', format: 'uuid'),
    new OA\Property(property: 'tax_rate', description: 'Default tax rate of the lines, chosen by the user', type: 'string', example: '3.80'),
    new OA\Property(property: 'category_tax_rates', description: 'Tax rate per folio category (room, city_tax, extra, restaurant, other)', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'date', description: 'Invoice date, defaults to today', type: 'string', format: 'date', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityFolioStatementResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'stay', type: 'object'),
        new OA\Property(property: 'charges', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'payments', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'totals', type: 'object', properties: [
            new OA\Property(property: 'charges', type: 'string', example: '420.00'),
            new OA\Property(property: 'payments', type: 'string', example: '420.00'),
            new OA\Property(property: 'balance', type: 'string', example: '0.00'),
            new OA\Property(property: 'by_category', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        ]),
    ]),
])]
#[OA\Schema(schema: 'HospitalityAvailabilityGridResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'room_type_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'total', type: 'integer'),
        new OA\Property(property: 'nights', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'date', type: 'string', format: 'date'),
            new OA\Property(property: 'booked', type: 'integer'),
            new OA\Property(property: 'held', description: 'Rooms held for open group blocks and not picked up yet', type: 'integer'),
            new OA\Property(property: 'available', type: 'integer'),
        ])),
    ])),
])]
final class HospitalityGroupOpenApi {}
