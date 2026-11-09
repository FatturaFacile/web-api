<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters, payloads and responses shared by the practice management endpoints.
 */
#[OA\Tag(name: 'Practice Management', description: 'Trust offices and law firms: activity control, fee notes from activities, multi-client period closing')]
#[OA\Response(response: 'PracticeUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PracticeForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PracticeNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PracticeUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'PracticeFeeNoteRequest', type: 'object', required: ['client_id', 'activity_ids', 'date', 'cpa_rate', 'ritenuta_rate', 'vat_rate'], properties: [
    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'activity_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'note_number', description: 'Defaults to the next number of the year', type: 'string', nullable: true),
    new OA\Property(property: 'date', type: 'string', format: 'date'),
    new OA\Property(property: 'cpa_rate', description: 'Social security contribution rate, entered by the user', type: 'string'),
    new OA\Property(property: 'ritenuta_rate', description: 'Withholding rate, entered by the user', type: 'string'),
    new OA\Property(property: 'vat_rate', description: 'VAT rate, entered by the user', type: 'string'),
])]
#[OA\Schema(schema: 'PracticeClosingRequest', type: 'object', required: ['client_id', 'period_start', 'period_end', 'checklist'], properties: [
    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'period_start', type: 'string', format: 'date'),
    new OA\Property(property: 'period_end', type: 'string', format: 'date'),
    new OA\Property(property: 'checklist', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'done', type: 'boolean'),
    ])),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PracticeRetainerRequest', type: 'object', required: ['client_id', 'received_on', 'amount'], properties: [
    new OA\Property(property: 'client_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'legal_case_id', description: 'Restrict the retainer to one case of the client', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'received_on', type: 'string', format: 'date'),
    new OA\Property(property: 'amount', type: 'string', example: '2000.00'),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PracticeRetainerApplyRequest', type: 'object', required: ['fee_note_id', 'amount'], properties: [
    new OA\Property(property: 'fee_note_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'amount', type: 'string', example: '500.00'),
])]
#[OA\Schema(schema: 'PracticeChecklistRequest', type: 'object', required: ['items'], properties: [
    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'done', type: 'boolean')])),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PracticeCloseRequest', type: 'object', properties: [
    new OA\Property(property: 'allow_unbilled', type: 'boolean'),
    new OA\Property(property: 'reason', description: 'Required when closing with unbilled activities', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PracticeRecordResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'status', type: 'string')]),
])]
#[OA\Schema(schema: 'PracticeDataResponse', type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])]
final class PracticeOpenApi {}
