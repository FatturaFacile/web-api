<?php

declare(strict_types=1);

namespace Modules\Documents\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the document management API.
 */
#[OA\Tag(name: 'Document Management', description: 'Folders, versions, tags, retention, legal hold and AI extraction proposals')]
#[OA\Schema(
    schema: 'DmsDocumentResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'folder_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'title', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'document_type', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'archived']),
        new OA\Property(property: 'current_version', type: 'integer'),
        new OA\Property(property: 'access_roles', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'metadata', type: 'object'),
        new OA\Property(property: 'retention_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'retention_until', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'legal_hold', type: 'boolean'),
        new OA\Property(property: 'legal_hold_reason', type: 'string', nullable: true),
        new OA\Property(property: 'is_checked_out', type: 'boolean', description: 'While true only the holder can add versions, change or delete the document'),
        new OA\Property(property: 'checked_out_by', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'checked_out_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'checkout_note', type: 'string', nullable: true),
        new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'links', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'versions', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsDocumentVersionResource')),
    ]
)]
#[OA\Schema(
    schema: 'DmsDocumentVersionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'version_number', type: 'integer'),
        new OA\Property(property: 'original_name', type: 'string'),
        new OA\Property(property: 'mime_type', type: 'string', nullable: true),
        new OA\Property(property: 'size', type: 'integer'),
        new OA\Property(property: 'sha256', type: 'string'),
        new OA\Property(property: 'is_attachment_reference', type: 'boolean'),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsFolderResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'access_roles', type: 'array', items: new OA\Items(type: 'string')),
    ]
)]
#[OA\Schema(
    schema: 'DmsTagResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'color', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsRetentionPolicyResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'document_type', type: 'string', nullable: true),
        new OA\Property(property: 'retention_months', type: 'integer'),
        new OA\Property(property: 'action', type: 'string', enum: ['archive', 'delete']),
        new OA\Property(property: 'is_active', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'DmsExtractionResource',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'document_version_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['proposed', 'confirmed', 'rejected', 'failed']),
        new OA\Property(property: 'driver', type: 'string', nullable: true),
        new OA\Property(property: 'proposed_fields', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'confirmed_fields', type: 'object', nullable: true),
        new OA\Property(property: 'failure_reason', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsStoreDocumentRequest',
    required: ['title', 'file'],
    type: 'object',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'file', type: 'string', format: 'binary'),
        new OA\Property(property: 'comment', type: 'string', maxLength: 500, nullable: true),
        new OA\Property(property: 'allow_duplicate', type: 'boolean', nullable: true, description: 'Store the file even when the same hash already exists in the tenant'),
        new OA\Property(property: 'folder_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'retention_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'document_type', type: 'string', nullable: true),
        new OA\Property(property: 'access_roles', type: 'array', items: new OA\Items(type: 'string'), nullable: true, description: 'Empty means every member'),
        new OA\Property(property: 'metadata', type: 'object', nullable: true),
        new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsUpdateDocumentRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'status', type: 'string', enum: ['active', 'archived']),
        new OA\Property(property: 'folder_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'retention_policy_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'document_type', type: 'string', nullable: true),
        new OA\Property(property: 'access_roles', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
        new OA\Property(property: 'metadata', type: 'object', nullable: true),
        new OA\Property(property: 'tag_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsStoreFolderRequest',
    required: ['name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 190),
        new OA\Property(property: 'parent_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'access_roles', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsStoreTagRequest',
    required: ['name'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 80),
        new OA\Property(property: 'color', type: 'string', maxLength: 20, nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsStoreRetentionPolicyRequest',
    required: ['name', 'retention_months', 'action'],
    type: 'object',
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 190),
        new OA\Property(property: 'document_type', type: 'string', nullable: true),
        new OA\Property(property: 'retention_months', type: 'integer', minimum: 1, maximum: 1200),
        new OA\Property(property: 'action', type: 'string', enum: ['archive', 'delete']),
        new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsLinkDocumentRequest',
    required: ['linkable_type', 'linkable_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'linkable_type', type: 'string', description: 'Alias of the business record, such as invoice, project or journal_entry'),
        new OA\Property(property: 'linkable_id', type: 'string', format: 'uuid'),
    ]
)]
#[OA\Schema(schema: 'DmsCheckOutRequest', type: 'object', properties: [new OA\Property(property: 'note', type: 'string', maxLength: 500, nullable: true, example: 'Updating the supplier terms')])]
#[OA\Schema(
    schema: 'DmsResolvedLink',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'linkable_type', type: 'string', example: 'invoice'),
        new OA\Property(property: 'linkable_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'label', type: 'string', nullable: true, example: 'INV-2027-0012'),
        new OA\Property(property: 'record_exists', type: 'boolean', description: 'False when the record was deleted after linking'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'DmsAuditEntry',
    type: 'object',
    properties: [
        new OA\Property(property: 'action', type: 'string', example: 'DOCUMENT_CHECKED_OUT'),
        new OA\Property(property: 'actor_user_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'ip_address', type: 'string', nullable: true),
        new OA\Property(property: 'details', type: 'object', additionalProperties: true),
    ]
)]
#[OA\Schema(schema: 'DmsPlaceLegalHoldRequest', required: ['reason'], type: 'object', properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 500)])]
#[OA\Schema(
    schema: 'DmsImportAttachmentRequest',
    required: ['attachment_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'attachment_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'folder_id', type: 'string', format: 'uuid', nullable: true),
    ]
)]
#[OA\Schema(schema: 'DmsConfirmExtractionRequest', required: ['fields'], type: 'object', properties: [new OA\Property(property: 'fields', type: 'object', description: 'The values as reviewed by the person')])]
#[OA\Schema(schema: 'DmsUpdateAiSettingRequest', required: ['external_providers_enabled'], type: 'object', properties: [new OA\Property(property: 'external_providers_enabled', type: 'boolean')])]
final class DocumentSchemas {}
