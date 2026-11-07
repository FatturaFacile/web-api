<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the helpdesk endpoints, derived from their API resources.
 */
#[OA\Schema(schema: 'HelpdeskTicketAttachmentResource', description: 'JSON representation produced by HelpdeskTicketAttachmentResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'message_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'original_name', type: 'string'),
    new OA\Property(property: 'mime_type', type: 'string'),
    new OA\Property(property: 'size', type: 'integer'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskTicketMessageResource', description: 'JSON representation produced by HelpdeskTicketMessageResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'author_type', type: 'string', enum: ['staff', 'customer', 'system']),
    new OA\Property(property: 'author_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'author_contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'author_name', type: 'string', nullable: true),
    new OA\Property(property: 'author_email', type: 'string', nullable: true),
    new OA\Property(property: 'visibility', type: 'string', enum: ['public', 'internal']),
    new OA\Property(property: 'channel', type: 'string', enum: ['web', 'portal', 'email', 'system']),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskTicketAttachmentResource')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskTicketActivityResource', description: 'JSON representation produced by HelpdeskTicketActivityResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'type', type: 'string', example: 'status_changed'),
    new OA\Property(property: 'from_value', type: 'string', nullable: true),
    new OA\Property(property: 'to_value', type: 'string', nullable: true),
    new OA\Property(property: 'payload', type: 'object'),
    new OA\Property(property: 'actor_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskTicketWatcherResource', description: 'JSON representation produced by HelpdeskTicketWatcherResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskPriorityMatrix', description: 'Priority by impact and urgency', type: 'object', properties: [
    new OA\Property(property: 'levels', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'matrix', type: 'object', example: ['high' => ['high' => 'critical', 'medium' => 'high', 'low' => 'medium']]),
])]
#[OA\Schema(schema: 'HelpdeskPortalTicketResource', description: 'JSON representation produced by PortalHelpdeskTicketResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ticket_number', type: 'string'),
    new OA\Property(property: 'subject', type: 'string'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'priority', type: 'string'),
    new OA\Property(property: 'merged_into_ticket_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'HelpdeskPortalMessageResource', description: 'JSON representation produced by PortalHelpdeskMessageResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'author_type', type: 'string', enum: ['staff', 'customer', 'system']),
    new OA\Property(property: 'author_name', type: 'string', nullable: true),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(ref: '#/components/schemas/HelpdeskTicketAttachmentResource')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
final class HelpdeskResponseSchemas {}
