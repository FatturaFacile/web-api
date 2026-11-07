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
final class HelpdeskRequestSchemas {}
