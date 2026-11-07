<?php

declare(strict_types=1);

namespace Modules\Hospitality\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters, payloads and responses shared by the restaurant endpoints.
 */
#[OA\Tag(name: 'Hospitality', description: 'Restaurant service on the POS: room plan, orders by course, kitchen tickets, split bills')]
#[OA\Parameter(parameter: 'HospitalityId', name: 'id', description: 'Record identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'HospitalityUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'HospitalityForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'HospitalityNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'HospitalityUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'HospitalityRoomRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'sort_order', type: 'integer'), new OA\Property(property: 'width', type: 'integer'), new OA\Property(property: 'height', type: 'integer'),
])]
#[OA\Schema(schema: 'HospitalityTableRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'seats', type: 'integer'),
    new OA\Property(property: 'shape', type: 'string', enum: ['square', 'round', 'rectangle']),
    new OA\Property(property: 'pos_x', type: 'integer'), new OA\Property(property: 'pos_y', type: 'integer'),
    new OA\Property(property: 'width', type: 'integer'), new OA\Property(property: 'height', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HospitalityOrderRequest', type: 'object', required: ['table_id', 'covers'], properties: [
    new OA\Property(property: 'table_id', type: 'string', format: 'uuid'), new OA\Property(property: 'covers', type: 'integer'), new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityItemsRequest', type: 'object', required: ['items'], properties: [
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', required: ['quantity'], properties: [
        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'quantity', type: 'string'),
        new OA\Property(property: 'unit_price', type: 'string'),
        new OA\Property(property: 'tax_rate', type: 'string'),
        new OA\Property(property: 'course', type: 'integer', example: 1),
        new OA\Property(property: 'station', type: 'string', enum: ['kitchen', 'bar', 'pizza', 'pastry']),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'HospitalityFireRequest', type: 'object', required: ['course'], properties: [new OA\Property(property: 'course', type: 'integer')])]
#[OA\Schema(schema: 'HospitalityMoveRequest', type: 'object', required: ['table_id'], properties: [new OA\Property(property: 'table_id', type: 'string', format: 'uuid')])]
#[OA\Schema(schema: 'HospitalityVoidRequest', type: 'object', required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])]
#[OA\Schema(schema: 'HospitalityTicketStatusRequest', type: 'object', required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['ready', 'served'])])]
#[OA\Schema(schema: 'HospitalityPaymentRequest', type: 'object', required: ['pos_session_id', 'mode', 'payment_method'], properties: [
    new OA\Property(property: 'pos_session_id', description: 'Open POS session', type: 'string', format: 'uuid'),
    new OA\Property(property: 'mode', type: 'string', enum: ['items', 'equal']),
    new OA\Property(property: 'lines', description: 'Items mode', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'order_item_id', type: 'string', format: 'uuid'), new OA\Property(property: 'quantity', type: 'string'),
    ])),
    new OA\Property(property: 'ways', description: 'Equal mode: guests still to pay, including this one', type: 'integer'),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'card', 'mixed']),
    new OA\Property(property: 'cash_amount', type: 'number', nullable: true),
    new OA\Property(property: 'cash_tendered', type: 'number', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'HospitalityRecordResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'status', type: 'string', nullable: true)]),
])]
#[OA\Schema(schema: 'HospitalityRecordCollection', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])]
#[OA\Schema(schema: 'HospitalityDataResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])]
final class HospitalityOpenApi {}
