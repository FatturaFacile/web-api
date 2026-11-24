<?php

declare(strict_types=1);

namespace Modules\WorkspaceSync\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the calendar and mail sync.
 */
#[OA\Tag(name: 'Workspace Sync', description: 'Read-only calendar and mail metadata from Google and Microsoft 365, per user and by consent')]
#[OA\Schema(
    schema: 'WorkspaceConnectionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'provider', type: 'string', enum: ['google', 'microsoft']),
        new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string', enum: ['calendar', 'mail'])),
        new OA\Property(property: 'consent_given_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'revoked_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_synced_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'WorkspaceItemResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'kind', type: 'string', enum: ['calendar_event', 'email']),
        new OA\Property(property: 'subject', type: 'string', nullable: true),
        new OA\Property(property: 'counterpart', type: 'string', nullable: true),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AuthorizeWorkspaceRequest',
    required: ['consent', 'scopes'],
    type: 'object',
    properties: [
        new OA\Property(property: 'consent', type: 'boolean', description: 'The user agrees to connect this account'),
        new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'string', enum: ['calendar', 'mail'])),
    ]
)]
#[OA\Schema(
    schema: 'WorkspaceCallbackRequest',
    required: ['code', 'state'],
    type: 'object',
    properties: [
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'state', type: 'string'),
    ]
)]
final class WorkspaceSchemas {}
