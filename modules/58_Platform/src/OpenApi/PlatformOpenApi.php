<?php

declare(strict_types=1);

namespace Modules\Platform\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses and payloads of the platform endpoints (recycle bin, notification centre). The Idempotency-Key and ETag /
 * If-Match behaviour is documented on the tag.
 */
#[OA\Tag(name: 'Platform', description: 'Cross-cutting platform features. Any POST, PUT, PATCH or DELETE accepts an Idempotency-Key header: a retry with the same key and payload replays the stored response (header Idempotent-Replayed: true), the same key with another payload answers 422 and while the first request runs 409. Contacts, invoices, quotes and products send an ETag; PUT, PATCH and DELETE with If-Match answer 412 when the record changed meanwhile. Also the recycle bin (deleted records: list, restore, destroy) and the in-app notification centre with per-channel preferences.')]
#[OA\Response(response: 'PlatformUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlatformForbidden', description: 'Only owners and administrators can use the recycle bin', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlatformNotFound', description: 'Record not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlatformUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'RecycleBinType', type: 'object', properties: [
    new OA\Property(property: 'type', type: 'string', example: 'contacts'),
    new OA\Property(property: 'count', type: 'integer'),
    new OA\Property(property: 'purgeable', description: 'false: the record can be restored but never destroyed (legal retention)', type: 'boolean'),
])]
#[OA\Schema(schema: 'RecycleBinRecord', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'type', type: 'string'),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'purgeable', type: 'boolean'),
])]
#[OA\Schema(schema: 'PlatformNotification', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'type', type: 'string'),
    new OA\Property(property: 'data', type: 'object', additionalProperties: true),
    new OA\Property(property: 'read_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PlatformNotificationPreference', type: 'object', properties: [
    new OA\Property(property: 'channel', type: 'string', enum: ['database', 'mail']),
    new OA\Property(property: 'category', type: 'string', enum: ['general', 'billing', 'crm', 'inventory', 'hr', 'system']),
    new OA\Property(property: 'enabled', type: 'boolean'),
    new OA\Property(property: 'frequency', type: 'string', enum: ['immediate', 'digest']),
])]
#[OA\Schema(schema: 'PlatformNotificationRealtime', type: 'object', properties: [
    new OA\Property(property: 'enabled', type: 'boolean'),
    new OA\Property(property: 'key', type: 'string', nullable: true),
    new OA\Property(property: 'host', type: 'string', nullable: true),
    new OA\Property(property: 'port', type: 'integer', nullable: true),
    new OA\Property(property: 'scheme', type: 'string', enum: ['http', 'https'], nullable: true),
    new OA\Property(property: 'channel', type: 'string', description: 'Private channel of the user'),
    new OA\Property(property: 'auth_endpoint', type: 'string'),
])]
#[OA\Schema(schema: 'PlatformNotificationSettings', type: 'object', properties: [
    new OA\Property(property: 'quiet_hours_enabled', type: 'boolean'),
    new OA\Property(property: 'quiet_start', type: 'string', example: '20:00'),
    new OA\Property(property: 'quiet_end', type: 'string', example: '08:00'),
    new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Rome'),
    new OA\Property(property: 'muted_until', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'PlatformNotificationPreferences', type: 'object', properties: [
    new OA\Property(property: 'frequencies', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'channels', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'preferences', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlatformNotificationPreference')),
])]
#[OA\Schema(schema: 'PlatformUpdateNotificationPreferencesRequest', type: 'object', required: ['preferences'], properties: [
    new OA\Property(property: 'preferences', type: 'array', items: new OA\Items(ref: '#/components/schemas/PlatformNotificationPreference')),
])]
final class PlatformOpenApi {}
