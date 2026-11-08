<?php

declare(strict_types=1);

namespace Modules\Contracts\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the lifecycle, obligations, parties and documents of contracts.
 */
#[OA\Schema(
    schema: 'ContractTransitionRequest',
    type: 'object',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'review', 'active', 'renewal', 'expired', 'terminated'], example: 'review'),
        new OA\Property(property: 'reason', type: 'string', nullable: true, maxLength: 1000, description: 'Required to terminate a contract'),
    ]
)]
#[OA\Schema(
    schema: 'ContractStatusChangeResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'from_status', type: 'string', nullable: true),
        new OA\Property(property: 'to_status', type: 'string'),
        new OA\Property(property: 'reason', type: 'string', nullable: true),
        new OA\Property(property: 'changed_by', type: 'string', format: 'uuid', nullable: true, description: 'Null when the daily job changed it'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'ContractObligationResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_kind', type: 'string', enum: ['customer', 'supplier']),
        new OA\Property(property: 'contract_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'type', type: 'string', enum: ['obligation', 'milestone']),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'responsible_party', type: 'string', enum: ['own', 'counterparty']),
        new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'amount', type: 'string', nullable: true, example: '5000.00'),
        new OA\Property(property: 'currency', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['open', 'completed', 'waived']),
        new OA\Property(property: 'is_overdue', type: 'boolean', description: 'Open and past its due date'),
        new OA\Property(property: 'remind_days_before', type: 'integer'),
        new OA\Property(property: 'reminded_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completion_note', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ContractSaveObligationRequest',
    type: 'object',
    required: ['title', 'type', 'due_date'],
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255, example: 'Deliver the first release'),
        new OA\Property(property: 'type', type: 'string', enum: ['obligation', 'milestone']),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'responsible_party', type: 'string', enum: ['own', 'counterparty'], default: 'own'),
        new OA\Property(property: 'owner_user_id', type: 'string', format: 'uuid', nullable: true, description: 'A member of the company, who is reminded'),
        new OA\Property(property: 'due_date', type: 'string', format: 'date'),
        new OA\Property(property: 'amount', type: 'string', nullable: true, example: '5000.00'),
        new OA\Property(property: 'currency', type: 'string', nullable: true, example: 'EUR'),
        new OA\Property(property: 'remind_days_before', type: 'integer', minimum: 0, maximum: 365, default: 7),
    ]
)]
#[OA\Schema(schema: 'ContractCloseObligationRequest', type: 'object', properties: [new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 1000)])]
#[OA\Schema(
    schema: 'ContractPartyResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'party_type', type: 'string', enum: ['contact', 'supplier', 'user', 'external']),
        new OA\Property(property: 'party_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'name', type: 'string', nullable: true, description: 'Resolved from the linked record, or the name given for an external party'),
        new OA\Property(property: 'role', type: 'string', enum: ['signatory', 'guarantor', 'subcontractor', 'witness', 'beneficiary', 'representative', 'other']),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ContractSavePartyRequest',
    type: 'object',
    required: ['party_type', 'role'],
    properties: [
        new OA\Property(property: 'party_type', type: 'string', enum: ['contact', 'supplier', 'user', 'external']),
        new OA\Property(property: 'party_id', type: 'string', format: 'uuid', nullable: true, description: 'Required unless the party is external; must belong to the company'),
        new OA\Property(property: 'name', type: 'string', nullable: true, maxLength: 255, description: 'Required for an external party'),
        new OA\Property(property: 'role', type: 'string', enum: ['signatory', 'guarantor', 'subcontractor', 'witness', 'beneficiary', 'representative', 'other']),
        new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(schema: 'ContractLinkDocumentRequest', type: 'object', required: ['document_id'], properties: [new OA\Property(property: 'document_id', type: 'string', format: 'uuid', description: 'A document of the document management module the caller can see')])]
final class ContractLifecyclePayloads {}
