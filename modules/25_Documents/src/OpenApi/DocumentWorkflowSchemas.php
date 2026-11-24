<?php

declare(strict_types=1);

namespace Modules\Documents\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the document approvals, the OCR pipeline and the preservation hash chain.
 */
#[OA\Tag(name: 'Document Approvals', description: 'Approval templates with ordered steps and the approval requests of documents')]
#[OA\Tag(name: 'Document OCR', description: 'OCR pipeline with pluggable engines and extraction status')]
#[OA\Tag(name: 'Document Preservation', description: 'Hash chain over closed preservation packages, retention and sealing status')]
#[OA\Schema(schema: 'DmsApprovalStep', type: 'object', required: ['name', 'approver_type'], properties: [
    new OA\Property(property: 'position', type: 'integer', readOnly: true),
    new OA\Property(property: 'name', type: 'string', maxLength: 160, example: 'Finance review'),
    new OA\Property(property: 'approver_type', type: 'string', enum: ['user', 'role']),
    new OA\Property(property: 'approver_user_id', description: 'Required for approver_type user; an active member of the company', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'approver_role', description: 'Required for approver_type role', type: 'string', nullable: true, example: 'accountant'),
])]
#[OA\Schema(schema: 'DmsApprovalTemplateResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'document_type', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsApprovalStep')),
])]
#[OA\Schema(schema: 'DmsSaveApprovalTemplateRequest', type: 'object', required: ['name', 'steps'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'document_type', type: 'string', maxLength: 60, nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'steps', type: 'array', minItems: 1, maxItems: 10, items: new OA\Items(ref: '#/components/schemas/DmsApprovalStep')),
])]
#[OA\Schema(schema: 'DmsRequestApprovalRequest', type: 'object', required: ['template_id'], properties: [
    new OA\Property(property: 'template_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true),
])]
#[OA\Schema(schema: 'DmsDecideApprovalRequest', type: 'object', properties: [
    new OA\Property(property: 'comment', description: 'Required when rejecting', type: 'string', maxLength: 1000, nullable: true),
])]
#[OA\Schema(schema: 'DmsApprovalRequestResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'document_title', type: 'string', nullable: true),
    new OA\Property(property: 'document_version_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'template_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'template_name', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected', 'cancelled']),
    new OA\Property(property: 'current_step', type: 'integer'),
    new OA\Property(property: 'total_steps', type: 'integer'),
    new OA\Property(property: 'current_step_detail', ref: '#/components/schemas/DmsApprovalStep', nullable: true),
    new OA\Property(property: 'requested_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'note', type: 'string', nullable: true),
    new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'decisions', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'step_position', type: 'integer'),
        new OA\Property(property: 'step_name', type: 'string'),
        new OA\Property(property: 'decided_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'decision', type: 'string', enum: ['approved', 'rejected']),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time'),
    ])),
])]
#[OA\Schema(schema: 'DmsOcrJobResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'document_version_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', description: 'Extraction status', type: 'string', enum: ['queued', 'processing', 'completed', 'failed']),
    new OA\Property(property: 'engine', type: 'string', nullable: true, example: 'plain_text'),
    new OA\Property(property: 'page_count', type: 'integer', nullable: true),
    new OA\Property(property: 'confidence', type: 'string', nullable: true, example: '98.50'),
    new OA\Property(property: 'failure_reason', type: 'string', nullable: true),
    new OA\Property(property: 'attempts', type: 'integer'),
    new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'finished_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'text_content', description: 'Only with include_text=1', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'DmsPreservationChainLinkResource', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'sequence', type: 'integer'),
    new OA\Property(property: 'package_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'package_hash', type: 'string'),
    new OA\Property(property: 'previous_link_hash', type: 'string'),
    new OA\Property(property: 'link_hash', type: 'string'),
    new OA\Property(property: 'anchored_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'DmsPreservationChainVerification', type: 'object', properties: [
    new OA\Property(property: 'valid', type: 'boolean'),
    new OA\Property(property: 'links_checked', type: 'integer'),
    new OA\Property(property: 'broken_at_sequence', type: 'integer', nullable: true),
    new OA\Property(property: 'reason', type: 'string', nullable: true, enum: ['sequence_gap', 'previous_hash_mismatch', 'link_hash_mismatch', 'package_missing', 'package_hash_mismatch']),
])]
#[OA\Schema(schema: 'DmsPreservationChainStatus', type: 'object', properties: [
    new OA\Property(property: 'closed_packages', type: 'integer'),
    new OA\Property(property: 'chained_packages', type: 'integer'),
    new OA\Property(property: 'unchained_packages', type: 'integer'),
    new OA\Property(property: 'packages', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'package_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'document_class', type: 'string'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'retention_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'retention_expired', type: 'boolean'),
        new OA\Property(property: 'timestamped', type: 'boolean'),
        new OA\Property(property: 'chained', type: 'boolean'),
        new OA\Property(property: 'chain_sequence', type: 'integer', nullable: true),
        new OA\Property(property: 'sealing_status', type: 'string', enum: ['sealed', 'awaiting_anchor']),
    ])),
])]
final class DocumentWorkflowSchemas {}
