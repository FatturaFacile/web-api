<?php

declare(strict_types=1);

namespace Modules\Contracts\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Named request and response schemas of the contract authoring endpoints (clause library, templates, redlining,
 * negotiation rounds and e-signature).
 */
#[OA\Schema(schema: 'ContractClauseRequest', type: 'object', required: ['code', 'title'], properties: [
    new OA\Property(property: 'code', type: 'string', maxLength: 60),
    new OA\Property(property: 'title', type: 'string', maxLength: 255),
    new OA\Property(property: 'category', type: 'string', maxLength: 60, nullable: true),
    new OA\Property(property: 'body', type: 'string', maxLength: 100000, description: 'First wording; stored as version 1.'),
])]
#[OA\Schema(schema: 'ContractClauseVersionRequest', type: 'object', required: ['body'], properties: [
    new OA\Property(property: 'body', type: 'string', maxLength: 100000),
    new OA\Property(property: 'change_note', type: 'string', maxLength: 500, nullable: true),
])]
#[OA\Schema(schema: 'ContractClauseStatusRequest', type: 'object', required: ['status'], properties: [new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved', 'retired'])])]
#[OA\Schema(schema: 'ContractClauseFallbackRequest', type: 'object', required: ['title', 'body'], properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 255),
    new OA\Property(property: 'body', type: 'string', maxLength: 100000),
    new OA\Property(property: 'rank', type: 'integer', minimum: 1, maximum: 50, nullable: true),
    new OA\Property(property: 'requires_approval', type: 'boolean'),
])]
#[OA\Schema(schema: 'ContractTemplateRequest', type: 'object', required: ['name', 'clauses'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 255),
    new OA\Property(property: 'contract_kind', type: 'string', enum: ['customer', 'supplier'], nullable: true),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'variables', type: 'array', nullable: true, items: new OA\Items(type: 'object', required: ['key'], properties: [
        new OA\Property(property: 'key', type: 'string', maxLength: 60),
        new OA\Property(property: 'label', type: 'string', nullable: true),
        new OA\Property(property: 'required', type: 'boolean'),
        new OA\Property(property: 'default', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'clauses', type: 'array', items: new OA\Items(type: 'object', required: ['clause_id'], properties: [
        new OA\Property(property: 'clause_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'is_optional', type: 'boolean'),
    ])),
])]
#[OA\Schema(schema: 'ContractTemplateAssembleRequest', type: 'object', properties: [
    new OA\Property(property: 'template_id', type: 'string', format: 'uuid', description: 'Required to create a version, ignored by the preview.'),
    new OA\Property(property: 'variables', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string', nullable: true)),
    new OA\Property(property: 'include_optional', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'fallback_ranks', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
])]
#[OA\Schema(schema: 'ContractDocumentVersionRequest', type: 'object', required: ['body'], properties: [
    new OA\Property(property: 'body', type: 'string', maxLength: 500000),
    new OA\Property(property: 'source', type: 'string', enum: ['edit', 'counterparty']),
    new OA\Property(property: 'notes', type: 'string', maxLength: 500, nullable: true),
])]
#[OA\Schema(schema: 'ContractCommentRequest', type: 'object', required: ['body'], properties: [
    new OA\Property(property: 'body', type: 'string', maxLength: 5000),
    new OA\Property(property: 'line_number', type: 'integer', minimum: 1, nullable: true),
])]
#[OA\Schema(schema: 'ContractNegotiationRoundOpenRequest', type: 'object', properties: [new OA\Property(property: 'summary', type: 'string', maxLength: 2000, nullable: true)])]
#[OA\Schema(schema: 'ContractNegotiationRoundCloseRequest', type: 'object', required: ['outcome'], properties: [
    new OA\Property(property: 'outcome', type: 'string'),
    new OA\Property(property: 'summary', type: 'string', maxLength: 2000, nullable: true),
])]
#[OA\Schema(schema: 'ContractSignatureRequestCreateRequest', type: 'object', required: ['document_version_id', 'signers'], properties: [
    new OA\Property(property: 'document_version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'provider', type: 'string', maxLength: 40, example: 'internal'),
    new OA\Property(property: 'order_mode', type: 'string', enum: ['sequential', 'parallel']),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'signers', type: 'array', minItems: 1, maxItems: 20, items: new OA\Items(type: 'object', required: ['name', 'email'], properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'email', type: 'string', format: 'email'),
    ])),
])]
#[OA\Schema(schema: 'ContractSignRequest', type: 'object', required: ['token'], properties: [new OA\Property(property: 'token', type: 'string', maxLength: 200, description: 'Personal signing token, shown once when the request is sent.')])]
#[OA\Schema(schema: 'ContractSignatureDeclineRequest', type: 'object', required: ['token', 'reason'], properties: [
    new OA\Property(property: 'token', type: 'string', maxLength: 200),
    new OA\Property(property: 'reason', type: 'string', maxLength: 500),
])]
#[OA\Schema(schema: 'ContractClauseResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'category', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'current_version_no', type: 'integer'),
    new OA\Property(property: 'current_body', type: 'string', nullable: true),
    new OA\Property(property: 'versions', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'fallbacks', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'ContractTemplateResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'contract_kind', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'variables', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'clauses', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'ContractDocumentVersionResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contract_kind', type: 'string'),
    new OA\Property(property: 'contract_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'version_no', type: 'integer'),
    new OA\Property(property: 'source', type: 'string'),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'body_hash', type: 'string'),
    new OA\Property(property: 'open_comments', type: 'integer'),
])]
#[OA\Schema(schema: 'ContractCommentResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'document_version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'line_number', type: 'integer', nullable: true),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'resolved']),
])]
#[OA\Schema(schema: 'ContractNegotiationRoundResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'round_no', type: 'integer'),
    new OA\Property(property: 'status', type: 'string'),
    new OA\Property(property: 'outcome', type: 'string', nullable: true),
    new OA\Property(property: 'summary', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ContractSignerResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'email', type: 'string'),
    new OA\Property(property: 'position', type: 'integer'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'notified', 'signed', 'declined']),
    new OA\Property(property: 'signed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'signature_hash', type: 'string', nullable: true),
    new OA\Property(property: 'decline_reason', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'ContractSignatureRequestResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contract_kind', type: 'string'),
    new OA\Property(property: 'contract_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'document_version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'provider', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'sent', 'completed', 'declined', 'cancelled', 'expired']),
    new OA\Property(property: 'order_mode', type: 'string'),
    new OA\Property(property: 'document_hash', type: 'string'),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'signers', type: 'array', items: new OA\Items(ref: '#/components/schemas/ContractSignerResource')),
    new OA\Property(property: 'events', type: 'array', items: new OA\Items(type: 'object')),
])]
#[OA\Schema(schema: 'ContractSignatureSendResponse', type: 'object', properties: [
    new OA\Property(property: 'data', ref: '#/components/schemas/ContractSignatureRequestResource'),
    new OA\Property(property: 'signing_tokens', type: 'object', description: 'One token per signer id, shown once.', additionalProperties: new OA\AdditionalProperties(type: 'string')),
])]
#[OA\Schema(schema: 'ContractPublicSigningRequest', type: 'object', properties: [
    new OA\Property(property: 'token', type: 'string', description: 'Echo of the link token, optional.'),
])]
#[OA\Schema(schema: 'ContractPublicSigningDeclineRequest', type: 'object', required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 500)])]
#[OA\Schema(schema: 'ContractPublicSigningView', type: 'object', properties: [
    new OA\Property(property: 'status', type: 'string', description: 'Status of the request.'),
    new OA\Property(property: 'signer_status', type: 'string', enum: ['pending', 'notified', 'signed', 'declined']),
    new OA\Property(property: 'signer_name', type: 'string'),
    new OA\Property(property: 'can_sign', type: 'boolean'),
    new OA\Property(property: 'waiting_for_previous', type: 'boolean'),
    new OA\Property(property: 'expires_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'document_hash', type: 'string'),
    new OA\Property(property: 'body', type: 'string'),
    new OA\Property(property: 'company_name', type: 'string', nullable: true),
    new OA\Property(property: 'signers', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'position', type: 'integer'),
        new OA\Property(property: 'status', type: 'string'),
    ])),
])]
final class ContractAuthoringPayloads {}
