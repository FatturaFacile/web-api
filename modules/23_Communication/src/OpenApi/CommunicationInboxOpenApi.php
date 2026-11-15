<?php

declare(strict_types=1);

namespace Modules\Communication\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the unified inbox, the campaigns and the contact preferences.
 */
#[OA\Tag(name: 'Communication Inbox', description: 'Unified inbox: conversations that aggregate the messages of every channel, with assignment and status')]
#[OA\Tag(name: 'Communication Campaigns', description: 'Campaigns to the audience of a CRM segment with scheduling and send log')]
#[OA\Tag(name: 'Communication Preferences', description: 'Per-channel opt-out of contacts, honouring the CRM consent')]
#[OA\Response(response: 'CommunicationInboxUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CommunicationInboxForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CommunicationInboxNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'CommunicationInboxUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'CommunicationConversation', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
    new OA\Property(property: 'counterparty_address', type: 'string'),
    new OA\Property(property: 'counterparty_name', type: 'string', nullable: true),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'subject', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'pending', 'resolved', 'closed']),
    new OA\Property(property: 'assigned_to', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'assigned_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'unread_count', type: 'integer'),
    new OA\Property(property: 'last_message_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'resolved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'messages', type: 'array', items: new OA\Items(ref: '#/components/schemas/CommunicationConversationMessage')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CommunicationConversationMessage', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'conversation_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'direction', type: 'string', enum: ['inbound', 'outbound']),
    new OA\Property(property: 'channel', type: 'string'),
    new OA\Property(property: 'sender_address', type: 'string', nullable: true),
    new OA\Property(property: 'sender_user_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'delivery_log_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'delivery_status', type: 'string', nullable: true, enum: ['sent', 'simulated', 'failed']),
    new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CommunicationInboundMessageRequest', type: 'object', required: ['channel', 'address', 'body'], properties: [
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
    new OA\Property(property: 'address', type: 'string', maxLength: 190),
    new OA\Property(property: 'name', type: 'string', nullable: true, maxLength: 160),
    new OA\Property(property: 'subject', type: 'string', nullable: true, maxLength: 255),
    new OA\Property(property: 'body', type: 'string', maxLength: 20000),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'CommunicationReplyRequest', type: 'object', required: ['body'], properties: [new OA\Property(property: 'body', type: 'string', maxLength: 20000)])]
#[OA\Schema(schema: 'CommunicationAssignRequest', type: 'object', required: ['user_id'], properties: [new OA\Property(property: 'user_id', description: 'Member of the company; null unassigns', type: 'string', format: 'uuid', nullable: true)])]
#[OA\Schema(schema: 'CommunicationConversationStatusRequest', type: 'object', required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['open', 'pending', 'resolved', 'closed'])])]
#[OA\Schema(schema: 'CommunicationCampaign', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
    new OA\Property(property: 'purpose', type: 'string', enum: ['marketing', 'newsletter']),
    new OA\Property(property: 'segment_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'subject', type: 'string', nullable: true),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'scheduled', 'sending', 'completed', 'cancelled']),
    new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'recipients_count', type: 'integer'),
    new OA\Property(property: 'sent_count', type: 'integer'),
    new OA\Property(property: 'failed_count', type: 'integer'),
    new OA\Property(property: 'suppressed_count', type: 'integer'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'CommunicationCampaignRequest', type: 'object', required: ['name', 'channel', 'body'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
    new OA\Property(property: 'purpose', type: 'string', enum: ['marketing', 'newsletter']),
    new OA\Property(property: 'segment_id', description: 'CRM segment of the audience', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'subject', type: 'string', nullable: true, maxLength: 255),
    new OA\Property(property: 'body', type: 'string', maxLength: 50000),
])]
#[OA\Schema(schema: 'CommunicationScheduleRequest', type: 'object', required: ['scheduled_at'], properties: [new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time')])]
#[OA\Schema(schema: 'CommunicationCampaignPreview', type: 'object', properties: [
    new OA\Property(property: 'total', type: 'integer'),
    new OA\Property(property: 'deliverable', type: 'integer'),
    new OA\Property(property: 'suppressed', description: 'Count per reason: no_address, opted_out, consent_withdrawn, no_consent', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
])]
#[OA\Schema(schema: 'CommunicationCampaignRecipient', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'lead_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'address', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'sent', 'failed', 'suppressed']),
    new OA\Property(property: 'suppression_reason', type: 'string', nullable: true),
    new OA\Property(property: 'delivery_log_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'error', type: 'string', nullable: true),
    new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'CommunicationContactPreference', type: 'object', properties: [
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
    new OA\Property(property: 'is_opted_out', type: 'boolean'),
    new OA\Property(property: 'reason', type: 'string', nullable: true),
    new OA\Property(property: 'changed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'marketing_consent', description: 'Effective CRM consent', type: 'string', nullable: true, enum: ['granted', 'withdrawn']),
    new OA\Property(property: 'can_receive_marketing', type: 'boolean'),
])]
#[OA\Schema(schema: 'CommunicationContactPreferenceRequest', type: 'object', required: ['channel', 'is_opted_out'], properties: [
    new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
    new OA\Property(property: 'is_opted_out', type: 'boolean'),
    new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 255),
])]
final class CommunicationInboxOpenApi {}
