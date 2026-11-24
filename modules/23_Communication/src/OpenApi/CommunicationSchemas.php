<?php

declare(strict_types=1);

namespace Modules\Communication\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the communication API: deliveries, channels and templates.
 */
#[OA\Tag(name: 'Communication Templates', description: 'Message templates per code, channel and language')]
#[OA\Schema(
    schema: 'CommunicationDeliveryLog',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'message_type', type: 'string', enum: ['document', 'message']),
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
        new OA\Property(property: 'driver', type: 'string', example: 'smtp'),
        new OA\Property(property: 'document_type', type: 'string', nullable: true, enum: ['invoice', 'quote']),
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'template_code', type: 'string', nullable: true),
        new OA\Property(property: 'locale', type: 'string', nullable: true, example: 'it_CH'),
        new OA\Property(property: 'recipient', type: 'string'),
        new OA\Property(property: 'subject', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['sent', 'simulated', 'failed']),
        new OA\Property(property: 'provider_reference', type: 'string', nullable: true),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'sent_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'CommunicationChannelResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
        new OA\Property(property: 'driver', type: 'string', enum: ['mail', 'smtp', 'twilio', 'telegram', 'local']),
        new OA\Property(property: 'is_enabled', type: 'boolean'),
        new OA\Property(property: 'has_credentials', type: 'boolean'),
        new OA\Property(property: 'settings', type: 'object', additionalProperties: true, description: 'Stored values that are not secret (SMTP host, port, encryption, username, sender; Twilio account and sender)', example: ['host' => 'smtp.company.example', 'port' => '587', 'encryption' => 'tls', 'from_address' => 'billing@company.example']),
        new OA\Property(property: 'secrets_set', type: 'array', items: new OA\Items(type: 'string'), description: 'Names of the secrets that are stored (never their values)', example: ['password']),
    ]
)]
#[OA\Schema(
    schema: 'CommunicationUpdateChannelRequest',
    type: 'object',
    required: ['driver'],
    properties: [
        new OA\Property(property: 'driver', type: 'string', enum: ['mail', 'smtp', 'twilio', 'telegram', 'local']),
        new OA\Property(property: 'is_enabled', type: 'boolean', default: true),
        new OA\Property(
            property: 'credentials',
            type: 'object',
            nullable: true,
            additionalProperties: true,
            description: 'Merged with the stored ones: a key sent replaces, null removes, a key left out is kept',
            example: ['host' => 'smtp.company.example', 'port' => 587, 'encryption' => 'tls', 'username' => 'billing@company.example', 'password' => 'secret', 'from_address' => 'billing@company.example', 'from_name' => 'Company Billing']
        ),
    ]
)]
#[OA\Schema(
    schema: 'CommunicationTemplateResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string', example: 'payment.reminder'),
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
        new OA\Property(property: 'locale', type: 'string', example: 'it_CH'),
        new OA\Property(property: 'subject', type: 'string', nullable: true, example: 'Sollecito fattura {{ number }}'),
        new OA\Property(property: 'body', type: 'string', example: 'Gentile {{ name }}, la fattura {{ number }} risulta scaduta.'),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'placeholders', type: 'array', items: new OA\Items(type: 'string'), example: ['number', 'name']),
    ]
)]
#[OA\Schema(
    schema: 'CommunicationSaveTemplateRequest',
    type: 'object',
    required: ['code', 'channel', 'locale', 'body'],
    properties: [
        new OA\Property(property: 'code', type: 'string', pattern: '^[a-z][a-z0-9_.]{1,99}$', example: 'payment.reminder'),
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
        new OA\Property(property: 'locale', type: 'string', pattern: '^[a-z]{2}(_[A-Z]{2})?$', example: 'de_CH'),
        new OA\Property(property: 'subject', type: 'string', nullable: true, maxLength: 255, description: 'Required for e-mail, one line'),
        new OA\Property(property: 'body', type: 'string', description: 'Plain text with {{ name }} placeholders; at most 20000 characters for e-mail, 1000 for the other channels'),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ]
)]
#[OA\Schema(
    schema: 'CommunicationSendMessageRequest',
    type: 'object',
    required: ['channel', 'recipient', 'template_code'],
    properties: [
        new OA\Property(property: 'channel', type: 'string', enum: ['email', 'whatsapp', 'telegram', 'sms']),
        new OA\Property(property: 'recipient', type: 'string', description: 'E-mail address, phone number or Telegram chat id / @username, by channel', example: 'accounting@customer.example'),
        new OA\Property(property: 'template_code', type: 'string', example: 'payment.reminder'),
        new OA\Property(property: 'locale', type: 'string', nullable: true, example: 'it_CH'),
        new OA\Property(property: 'variables', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'string'), example: ['name' => 'Rossi SA', 'number' => 'INV-2027-0042']),
    ]
)]
final class CommunicationSchemas {}
