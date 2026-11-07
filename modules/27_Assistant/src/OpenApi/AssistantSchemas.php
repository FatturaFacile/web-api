<?php

declare(strict_types=1);

namespace Modules\Assistant\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the business data assistant.
 */
#[OA\Tag(name: 'Assistant', description: 'Natural-language questions answered only from a fixed catalogue of read-only queries')]
#[OA\Schema(schema: 'AskAssistantRequest', required: ['question'], type: 'object', properties: [new OA\Property(property: 'question', type: 'string', maxLength: 500)])]
#[OA\Schema(
    schema: 'AssistantAnswer',
    type: 'object',
    properties: [
        new OA\Property(property: 'outcome', type: 'string', enum: ['answered', 'unmatched', 'forbidden', 'failed']),
        new OA\Property(property: 'query', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'parameters', type: 'object'),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
    ]
)]
#[OA\Schema(
    schema: 'AssistantCatalogueEntry',
    type: 'object',
    properties: [
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'parameters', type: 'object'),
    ]
)]
#[OA\Schema(
    schema: 'AssistantLogResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'role', type: 'string', nullable: true),
        new OA\Property(property: 'question', type: 'string'),
        new OA\Property(property: 'resolver', type: 'string'),
        new OA\Property(property: 'query_key', type: 'string', nullable: true),
        new OA\Property(property: 'parameters', type: 'object'),
        new OA\Property(property: 'outcome', type: 'string'),
        new OA\Property(property: 'row_count', type: 'integer'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
final class AssistantSchemas {}
