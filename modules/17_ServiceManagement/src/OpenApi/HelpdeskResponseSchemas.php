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
#[OA\Schema(schema: 'HelpdeskBulkResult', description: 'Outcome of a bulk action on one ticket', type: 'object', properties: [
    new OA\Property(property: 'ticket_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'ok', type: 'boolean'),
    new OA\Property(property: 'error', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'HelpdeskCannedResponseResource', description: 'JSON representation produced by CannedResponseResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'category', type: 'string', nullable: true),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'is_shared', type: 'boolean'),
    new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskMacroResource', description: 'JSON representation produced by MacroResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'actions', type: 'object'),
    new OA\Property(property: 'is_shared', type: 'boolean'),
    new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskSavedViewResource', description: 'JSON representation produced by SavedViewResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'filters', type: 'object'),
    new OA\Property(property: 'position', type: 'integer'),
    new OA\Property(property: 'is_shared', type: 'boolean'),
    new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskKnowledgeCategoryResource', description: 'JSON representation produced by KnowledgeCategoryResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'visibility', type: 'string', enum: ['internal', 'public']),
    new OA\Property(property: 'position', type: 'integer'),
])]
#[OA\Schema(schema: 'HelpdeskKnowledgeArticleResource', description: 'JSON representation produced by KnowledgeArticleResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'article_number', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'summary', type: 'string', nullable: true),
    new OA\Property(property: 'content', type: 'string'),
    new OA\Property(property: 'knowledge_category_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'visibility', type: 'string', enum: ['internal', 'public']),
    new OA\Property(property: 'is_published', type: 'boolean'),
    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'current_version', type: 'integer'),
    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'views_count', type: 'integer'),
    new OA\Property(property: 'helpful_count', type: 'integer'),
    new OA\Property(property: 'author_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskKnowledgeArticleVersionResource', description: 'JSON representation produced by KnowledgeArticleVersionResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'article_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version', type: 'integer'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'summary', type: 'string', nullable: true),
    new OA\Property(property: 'content', type: 'string'),
    new OA\Property(property: 'author_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'change_note', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskPortalKnowledgeArticleResource', description: 'JSON representation produced by PortalKnowledgeArticleResource; content only when a single article is read', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'article_number', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'summary', type: 'string', nullable: true),
    new OA\Property(property: 'content', type: 'string'),
    new OA\Property(property: 'knowledge_category_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'published_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'HelpdeskPortalCsatSurvey', description: 'A satisfaction survey as its link shows it', type: 'object', properties: [
    new OA\Property(property: 'ticket_number', type: 'string'),
    new OA\Property(property: 'subject', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'completed']),
    new OA\Property(property: 'can_answer', type: 'boolean'),
    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'HelpdeskDurationStats', description: 'Count, mean, median and 90th percentile, in minutes', type: 'object', properties: [
    new OA\Property(property: 'count', type: 'integer'),
    new OA\Property(property: 'mean', type: 'number', nullable: true),
    new OA\Property(property: 'p50', type: 'number', nullable: true),
    new OA\Property(property: 'p90', type: 'number', nullable: true),
])]
#[OA\Schema(schema: 'HelpdeskReport', description: 'Helpdesk figures of a tenant for a period', type: 'object', properties: [
    new OA\Property(property: 'period', properties: [new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')], type: 'object'),
    new OA\Property(property: 'sla', properties: [
        new OA\Property(property: 'first_response', properties: [new OA\Property(property: 'tickets', type: 'integer'), new OA\Property(property: 'met', type: 'integer'), new OA\Property(property: 'breached', type: 'integer'), new OA\Property(property: 'compliance_percent', type: 'number', nullable: true)], type: 'object'),
        new OA\Property(property: 'resolution', properties: [new OA\Property(property: 'tickets', type: 'integer'), new OA\Property(property: 'met', type: 'integer'), new OA\Property(property: 'breached', type: 'integer'), new OA\Property(property: 'compliance_percent', type: 'number', nullable: true)], type: 'object'),
    ], type: 'object'),
    new OA\Property(property: 'first_response_minutes', ref: '#/components/schemas/HelpdeskDurationStats'),
    new OA\Property(property: 'resolution_minutes', ref: '#/components/schemas/HelpdeskDurationStats'),
    new OA\Property(property: 'backlog', properties: [new OA\Property(property: 'by_status', type: 'object'), new OA\Property(property: 'by_age', type: 'object', example: ['under_1_day' => 3, '1_to_3_days' => 1, '3_to_7_days' => 0, '7_to_30_days' => 2, 'over_30_days' => 0]), new OA\Property(property: 'total', type: 'integer')], type: 'object'),
    new OA\Property(property: 'volume', properties: [new OA\Property(property: 'by_channel', type: 'object'), new OA\Property(property: 'by_category', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'name', type: 'string', nullable: true), new OA\Property(property: 'tickets', type: 'integer')], type: 'object')), new OA\Property(property: 'total', type: 'integer')], type: 'object'),
    new OA\Property(property: 'agent_workload', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'technician_id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'open_tickets', type: 'integer'), new OA\Property(property: 'resolved_in_period', type: 'integer'), new OA\Property(property: 'mean_resolution_minutes', type: 'number', nullable: true)], type: 'object')),
    new OA\Property(property: 'csat_by_team', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'team_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'name', type: 'string', nullable: true), new OA\Property(property: 'responses', type: 'integer'), new OA\Property(property: 'mean_score', type: 'number', nullable: true), new OA\Property(property: 'satisfied_percent', type: 'number', nullable: true)], type: 'object')),
])]
final class HelpdeskResponseSchemas {}
