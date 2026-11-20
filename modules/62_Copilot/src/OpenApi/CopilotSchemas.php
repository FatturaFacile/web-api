<?php

declare(strict_types=1);

namespace Modules\Copilot\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the ERP Copilot.
 */
#[OA\Tag(name: 'Copilot', description: 'Natural-language ERP assistant: governed read tools, regulatory knowledge with citations, and human-confirmed actions')]
#[OA\Schema(schema: 'SendCopilotMessageRequest', required: ['prompt'], type: 'object', properties: [
    new OA\Property(property: 'prompt', type: 'string', maxLength: 4000),
    new OA\Property(property: 'locale', type: 'string', enum: ['it', 'de', 'fr', 'en', 'it-CH', 'de-CH', 'fr-CH', 'it-IT'], nullable: true, description: 'Language and region of the answer; the region picks the Swiss or Italian regulatory passages. Defaults to the conversation locale'),
    new OA\Property(property: 'context', type: 'object', nullable: true, description: 'The ERP screen the user is looking at', properties: [
        new OA\Property(property: 'route', type: 'string', nullable: true),
        new OA\Property(property: 'entity_type', type: 'string', nullable: true),
        new OA\Property(property: 'entity_id', type: 'string', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'CreateCopilotConversationRequest', type: 'object', properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 160, nullable: true),
    new OA\Property(property: 'locale', type: 'string', enum: ['it', 'de', 'fr', 'en', 'it-CH', 'de-CH', 'fr-CH', 'it-IT'], nullable: true),
    new OA\Property(property: 'context_route', type: 'string', nullable: true),
    new OA\Property(property: 'context_entity_type', type: 'string', nullable: true),
    new OA\Property(property: 'context_entity_id', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ConfirmCopilotActionRequest', type: 'object', properties: [
    new OA\Property(property: 'overrides', type: 'object', nullable: true, additionalProperties: true, description: 'New values by dotted argument path, only for the fields listed in `editable_fields` of the proposed action, e.g. {"items.0.quantity": 5}'),
])]
#[OA\Schema(schema: 'RejectCopilotActionRequest', type: 'object', properties: [
    new OA\Property(property: 'reason', type: 'string', maxLength: 500, nullable: true),
])]
#[OA\Schema(schema: 'IndexCopilotKnowledgeRequest', required: ['documents'], type: 'object', properties: [
    new OA\Property(property: 'documents', type: 'array', items: new OA\Items(type: 'object', required: ['title', 'content', 'jurisdiction', 'locale'], properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 200),
        new OA\Property(property: 'section_ref', type: 'string', maxLength: 120, nullable: true),
        new OA\Property(property: 'content', type: 'string'),
        new OA\Property(property: 'jurisdiction', type: 'string', enum: ['CH', 'IT', 'GLOBAL']),
        new OA\Property(property: 'locale', type: 'string', enum: ['it', 'de', 'fr', 'en']),
    ])),
])]
#[OA\Schema(
    schema: 'CopilotConversationResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'locale', type: 'string'),
        new OA\Property(property: 'context_route', type: 'string', nullable: true),
        new OA\Property(property: 'context_entity_type', type: 'string', nullable: true),
        new OA\Property(property: 'context_entity_id', type: 'string', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'messages', type: 'array', description: 'Present on the detail only', items: new OA\Items(ref: '#/components/schemas/CopilotMessageResource')),
    ]
)]
#[OA\Schema(
    schema: 'CopilotMessageResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'conversation_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant']),
        new OA\Property(property: 'content_markdown', type: 'string'),
        new OA\Property(property: 'citations', type: 'array', description: 'Regulatory citations (type regulatory, label like [LIVA Art. 25]) and clickable entity citations (type entity, label like #Invoice-2026-0042, with entity_type, entity_id and route)', items: new OA\Items(ref: '#/components/schemas/CopilotCitation')),
        new OA\Property(property: 'visualization', ref: '#/components/schemas/CopilotVisualization', nullable: true),
        new OA\Property(property: 'proposed_action', ref: '#/components/schemas/CopilotProposedAction', nullable: true),
        new OA\Property(property: 'action_status', type: 'string', enum: ['pending', 'confirmed', 'rejected', 'executed', 'failed'], nullable: true),
        new OA\Property(property: 'token_usage', type: 'object', nullable: true, properties: [
            new OA\Property(property: 'prompt_tokens', type: 'integer'),
            new OA\Property(property: 'completion_tokens', type: 'integer'),
            new OA\Property(property: 'total_tokens', type: 'integer'),
            new OA\Property(property: 'provider', type: 'string'),
            new OA\Property(property: 'model', type: 'string'),
        ]),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CopilotCitation',
    type: 'object',
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['regulatory', 'entity']),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'title', type: 'string', nullable: true),
        new OA\Property(property: 'section_ref', type: 'string', nullable: true),
        new OA\Property(property: 'jurisdiction', type: 'string', enum: ['CH', 'IT', 'GLOBAL'], nullable: true),
        new OA\Property(property: 'entity_type', type: 'string', nullable: true),
        new OA\Property(property: 'entity_id', type: 'string', nullable: true),
        new OA\Property(property: 'route', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CopilotVisualization',
    type: 'object',
    properties: [
        new OA\Property(property: 'type', type: 'string', enum: ['bar', 'line', 'pie', 'kpi_card', 'table']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'columns', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'rows', type: 'array', description: 'The summary table, also the source of the CSV export', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'x', type: 'string', nullable: true),
        new OA\Property(property: 'labels', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'datasets', type: 'array', nullable: true, items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'number')),
        ])),
        new OA\Property(property: 'metric', type: 'string', nullable: true),
        new OA\Property(property: 'value', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'CopilotProposedAction',
    type: 'object',
    properties: [
        new OA\Property(property: 'tool', type: 'string', enum: ['create_sales_quote', 'create_invoice_draft', 'request_employee_leave']),
        new OA\Property(property: 'module', type: 'string'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'confirmed', 'rejected', 'executed', 'failed']),
        new OA\Property(property: 'summary', type: 'string'),
        new OA\Property(property: 'arguments', type: 'object', additionalProperties: true),
        new OA\Property(property: 'details', type: 'object', additionalProperties: true, description: 'What the confirmation card shows'),
        new OA\Property(property: 'editable_fields', type: 'array', items: new OA\Items(type: 'string'), description: 'Dotted argument paths the user may change before confirming'),
        new OA\Property(property: 'result', type: 'object', nullable: true, additionalProperties: true, description: 'Present once executed: entity_type, entity_id, label, route'),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'proposed_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CopilotAuditLogResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'action', type: 'string', example: 'COPILOT_ACTION_EXECUTED'),
        new OA\Property(property: 'actor_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'resource_type', type: 'string'),
        new OA\Property(property: 'resource_id', type: 'string'),
        new OA\Property(property: 'details', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'event_timestamp', type: 'string', format: 'date-time'),
    ]
)]
final class CopilotSchemas {}
