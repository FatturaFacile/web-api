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
#[OA\Schema(schema: 'HelpdeskSupportMailboxResource', description: 'JSON representation produced by SupportMailboxResource. Credentials are never returned.', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'email_address', type: 'string', format: 'email'),
    new OA\Property(property: 'imap_host', type: 'string'),
    new OA\Property(property: 'imap_port', type: 'integer'),
    new OA\Property(property: 'has_credentials', type: 'boolean'),
    new OA\Property(property: 'default_team_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'default_category_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'default_priority', type: 'string'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'last_uid', type: 'integer'),
    new OA\Property(property: 'last_polled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'last_error', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskSupportMailboxMessageResource', description: 'JSON representation produced by SupportMailboxMessageResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'mailbox_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'uid', type: 'integer'),
    new OA\Property(property: 'message_id', type: 'string', nullable: true),
    new OA\Property(property: 'from_email', type: 'string', nullable: true),
    new OA\Property(property: 'subject', type: 'string', nullable: true),
    new OA\Property(property: 'outcome', type: 'string', enum: ['created', 'threaded', 'duplicate', 'ignored_auto_reply', 'ignored_loop', 'failed']),
    new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskRoutingRuleResource', description: 'JSON representation produced by RoutingRuleResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'position', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'stop_processing', type: 'boolean'),
    new OA\Property(property: 'conditions', type: 'object'),
    new OA\Property(property: 'actions', type: 'object'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskBusinessHoursCalendarResource', description: 'JSON representation produced by BusinessHoursCalendarResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'timezone', type: 'string'),
    new OA\Property(property: 'weekly_schedule', type: 'object'),
    new OA\Property(property: 'holidays', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'hr_holiday_calendar_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskSettingResource', description: 'JSON representation produced by HelpdeskSettingResource', type: 'object', properties: [
    new OA\Property(property: 'reopen_window_days', type: 'integer'),
    new OA\Property(property: 'business_hours_calendar_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sla_warning_percent', type: 'integer'),
    new OA\Property(property: 'csat_enabled', type: 'boolean'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true),
])]
final class HelpdeskResponseSchemas {}
