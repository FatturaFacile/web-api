<?php

declare(strict_types=1);

namespace Modules\Assistant\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the business data assistant.
 */
#[OA\Tag(name: 'Assistant', description: 'Natural-language questions answered only from a fixed catalogue of read-only queries')]
#[OA\Schema(schema: 'AskAssistantRequest', required: ['question'], type: 'object', properties: [
    new OA\Property(property: 'question', type: 'string', maxLength: 500),
    new OA\Property(property: 'thread_id', type: 'string', format: 'uuid', nullable: true, description: 'An own conversation to continue; omitted, a new thread is started'),
])]
#[OA\Schema(schema: 'AssistantFeedbackRequest', required: ['rating'], type: 'object', properties: [
    new OA\Property(property: 'rating', type: 'string', enum: ['helpful', 'not_helpful']),
    new OA\Property(property: 'comment', type: 'string', maxLength: 1000, nullable: true),
])]
#[OA\Schema(
    schema: 'AssistantThreadResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'last_message_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'messages', type: 'array', description: 'Present on the detail only', items: new OA\Items(ref: '#/components/schemas/AssistantLogResource')),
    ]
)]
#[OA\Schema(
    schema: 'AssistantAnswer',
    type: 'object',
    properties: [
        new OA\Property(property: 'outcome', type: 'string', enum: ['answered', 'unmatched', 'forbidden', 'failed']),
        new OA\Property(property: 'query', type: 'string', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'parameters', type: 'object'),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'thread_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'log_id', type: 'string', format: 'uuid', description: 'Identifier of this answer, for the feedback endpoint'),
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
        new OA\Property(property: 'thread_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'feedback', type: 'string', enum: ['helpful', 'not_helpful'], nullable: true),
        new OA\Property(property: 'feedback_comment', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
final class AssistantSchemas {}
