<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request payloads of the helpdesk endpoints, derived from their form requests.
 */
#[OA\Schema(schema: 'HelpdeskUpdateTicketRequest', description: 'Request payload validated by UpdateHelpdeskTicketRequest. Every field is optional.', type: 'object', properties: [
    new OA\Property(property: 'subject', type: 'string', maxLength: 200),
    new OA\Property(property: 'description', type: 'string', maxLength: 20000),
    new OA\Property(property: 'type', type: 'string', enum: ['incident', 'request', 'problem', 'change', 'inquiry']),
    new OA\Property(property: 'impact', type: 'string', enum: ['low', 'medium', 'high'], nullable: true),
    new OA\Property(property: 'urgency', type: 'string', enum: ['low', 'medium', 'high'], nullable: true),
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'category_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'customer_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'service_contract_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'asset_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string', maxLength: 50)),
    new OA\Property(property: 'contact_email', type: 'string', format: 'email', nullable: true),
    new OA\Property(property: 'contact_phone', type: 'string', nullable: true),
    new OA\Property(property: 'contact_name', type: 'string', nullable: true),
    new OA\Property(property: 'assigned_team_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'assigned_technician_id', type: 'string', format: 'uuid', nullable: true),
])]
#[OA\Schema(schema: 'HelpdeskChangeTicketStatusRequest', description: 'Request payload validated by ChangeTicketStatusRequest', required: ['status'], type: 'object', properties: [
    new OA\Property(property: 'status', type: 'string', enum: ['new', 'open', 'pending_customer', 'on_hold', 'resolved', 'closed']),
    new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'HelpdeskMergeTicketRequest', description: 'Request payload validated by MergeTicketRequest', required: ['target_ticket_id'], type: 'object', properties: [
    new OA\Property(property: 'target_ticket_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'HelpdeskSplitTicketRequest', description: 'Request payload validated by SplitTicketRequest', required: ['subject', 'message_ids'], type: 'object', properties: [
    new OA\Property(property: 'subject', type: 'string', maxLength: 200),
    new OA\Property(property: 'message_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
])]
#[OA\Schema(schema: 'HelpdeskAddTicketWatcherRequest', description: 'Request payload validated by AddTicketWatcherRequest', required: ['user_id'], type: 'object', properties: [
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'HelpdeskStoreTicketMessageRequest', description: 'Request payload validated by StoreTicketMessageRequest', required: ['body'], type: 'object', properties: [
    new OA\Property(property: 'body', type: 'string', maxLength: 20000),
    new OA\Property(property: 'visibility', type: 'string', enum: ['public', 'internal'], default: 'public'),
    new OA\Property(property: 'status', type: 'string', enum: ['new', 'open', 'pending_customer', 'on_hold', 'resolved', 'closed'], nullable: true),
    new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
])]
#[OA\Schema(schema: 'HelpdeskPortalReplyRequest', description: 'Request payload validated by PortalHelpdeskReplyRequest', required: ['message'], type: 'object', properties: [
    new OA\Property(property: 'message', type: 'string', maxLength: 10000),
    new OA\Property(property: 'author_name', type: 'string', maxLength: 150, nullable: true),
    new OA\Property(property: 'attachments', type: 'array', items: new OA\Items(type: 'string', format: 'binary')),
])]
#[OA\Schema(schema: 'HelpdeskPortalStoreTicketRequest', description: 'Request payload validated by PortalHelpdeskStoreTicketRequest', required: ['subject', 'description'], type: 'object', properties: [
    new OA\Property(property: 'subject', type: 'string', maxLength: 200),
    new OA\Property(property: 'description', type: 'string', maxLength: 10000),
])]
#[OA\Schema(schema: 'HelpdeskSaveSupportMailboxRequest', description: 'Request payload validated by SaveSupportMailboxRequest. On creation name, email_address, imap_host, imap_username and imap_password are required.', type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 150),
    new OA\Property(property: 'email_address', type: 'string', format: 'email', maxLength: 180),
    new OA\Property(property: 'imap_host', type: 'string', maxLength: 180),
    new OA\Property(property: 'imap_port', type: 'integer', default: 993),
    new OA\Property(property: 'imap_username', type: 'string', maxLength: 255, writeOnly: true),
    new OA\Property(property: 'imap_password', type: 'string', maxLength: 255, writeOnly: true),
    new OA\Property(property: 'default_team_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'default_category_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'default_priority', type: 'string', enum: ['low', 'medium', 'high', 'critical']),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HelpdeskSaveRoutingRuleRequest', description: 'Request payload validated by SaveRoutingRuleRequest. Empty or missing conditions match every ticket.', type: 'object', properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 150),
    new OA\Property(property: 'position', type: 'integer', description: 'Rules run from the lowest position'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'stop_processing', type: 'boolean', description: 'No further rule runs once this one matched'),
    new OA\Property(property: 'conditions', properties: [
        new OA\Property(property: 'category_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'sources', type: 'array', items: new OA\Items(type: 'string', enum: ['portal', 'email', 'phone', 'chat', 'api', 'field', 'monitoring'])),
        new OA\Property(property: 'customer_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'keywords', type: 'array', items: new OA\Items(type: 'string'), description: 'Any of them in the subject or description'),
        new OA\Property(property: 'priorities', type: 'array', items: new OA\Items(type: 'string', enum: ['low', 'medium', 'high', 'critical'])),
    ], type: 'object', nullable: true),
    new OA\Property(property: 'actions', properties: [
        new OA\Property(property: 'assign_team_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assign_technician_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'assignment_strategy', type: 'string', enum: ['manual', 'round_robin', 'least_loaded'], nullable: true),
        new OA\Property(property: 'set_priority', type: 'string', enum: ['low', 'medium', 'high', 'critical'], nullable: true),
        new OA\Property(property: 'add_tags', type: 'array', items: new OA\Items(type: 'string')),
    ], type: 'object'),
])]
#[OA\Schema(schema: 'HelpdeskSaveBusinessHoursCalendarRequest', description: 'Request payload validated by SaveBusinessHoursCalendarRequest', type: 'object', properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 60),
    new OA\Property(property: 'name', type: 'string', maxLength: 150),
    new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Rome'),
    new OA\Property(property: 'weekly_schedule', type: 'object', example: ['mon' => [['08:00', '12:00'], ['13:00', '17:00']], 'fri' => [['08:00', '16:00']]]),
    new OA\Property(property: 'holidays', type: 'array', items: new OA\Items(type: 'string', example: '2027-12-25')),
    new OA\Property(property: 'hr_holiday_calendar_id', type: 'string', format: 'uuid', nullable: true, description: 'HR holiday calendar whose holidays also stop the SLA clock'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'HelpdeskUpdateSettingsRequest', description: 'Request payload validated by UpdateHelpdeskSettingsRequest', type: 'object', properties: [
    new OA\Property(property: 'reopen_window_days', type: 'integer', minimum: 0, maximum: 365),
    new OA\Property(property: 'business_hours_calendar_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'sla_warning_percent', type: 'integer', minimum: 1, maximum: 99),
    new OA\Property(property: 'csat_enabled', type: 'boolean'),
])]
#[OA\Schema(schema: 'HelpdeskUpdateTeamAssignmentRequest', description: 'Request payload validated by UpdateTeamAssignmentRequest', required: ['assignment_strategy'], type: 'object', properties: [
    new OA\Property(property: 'assignment_strategy', type: 'string', enum: ['manual', 'round_robin', 'least_loaded']),
])]
#[OA\Schema(schema: 'HelpdeskAutoAssignTicketRequest', description: 'Request payload validated by AutoAssignTicketRequest', required: ['strategy'], type: 'object', properties: [
    new OA\Property(property: 'team_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'strategy', type: 'string', enum: ['round_robin', 'least_loaded']),
])]
final class HelpdeskRequestSchemas {}
