<?php

declare(strict_types=1);

namespace Modules\Collaboration\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the collaboration endpoints.
 */
#[OA\Tag(name: 'Collaboration', description: 'Cross-cutting collaboration: global search across the key entities, comments with mentions, shared tags and the activity timeline of any record')]
#[OA\Response(response: 'CollabUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CollabForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CollabNotFound', description: 'Record, comment or tag not found in the active company, or unknown record type', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CollabUnprocessable', description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'CollabSearchResult', type: 'object', properties: [
    new OA\Property(property: 'type', type: 'string', example: 'contact', description: 'Record type key'),
    new OA\Property(property: 'type_label', type: 'string', description: 'Translated name of the record type'),
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'subtitle', type: 'string', nullable: true),
    new OA\Property(property: 'path', type: 'string', example: '/crm/clients', description: 'Frontend route of the record'),
])]
#[OA\Schema(schema: 'CollabRecordType', type: 'object', properties: [
    new OA\Property(property: 'key', type: 'string', example: 'invoice'),
    new OA\Property(property: 'label', type: 'string'),
])]
#[OA\Schema(schema: 'CollabMentionableUser', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'email', type: 'string'),
])]
#[OA\Schema(schema: 'CollabComment', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'record_type', type: 'string'),
    new OA\Property(property: 'record_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'mentions', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'author', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
    ]),
    new OA\Property(property: 'can_edit', type: 'boolean'),
    new OA\Property(property: 'can_delete', type: 'boolean'),
    new OA\Property(property: 'edited_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CollabCommentRequest', type: 'object', required: ['body'], properties: [
    new OA\Property(property: 'body', type: 'string', maxLength: 5000),
    new OA\Property(property: 'mentions', type: 'array', maxItems: 20, items: new OA\Items(type: 'string', format: 'uuid'), description: 'Members of the company to notify'),
])]
#[OA\Schema(schema: 'CollabTag', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'color', type: 'string', nullable: true, example: '#1677ff'),
    new OA\Property(property: 'usage_count', type: 'integer', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CollabTagRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 60),
    new OA\Property(property: 'color', type: 'string', nullable: true, example: '#1677ff'),
])]
#[OA\Schema(schema: 'CollabAttachTagRequest', type: 'object', description: 'Either tag_id or name is required', properties: [
    new OA\Property(property: 'tag_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'name', type: 'string', maxLength: 60, nullable: true),
])]
#[OA\Schema(schema: 'CollabActivity', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'action', type: 'string', example: 'UPDATED'),
    new OA\Property(property: 'module_code', type: 'string', nullable: true),
    new OA\Property(property: 'actor', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string', nullable: true),
    ]),
    new OA\Property(property: 'changed_fields', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
])]
final class CollaborationOpenApi {}
