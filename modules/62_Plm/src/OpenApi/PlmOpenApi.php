<?php

declare(strict_types=1);

namespace Modules\Plm\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the PLM endpoints.
 */
#[OA\Tag(name: 'PLM', description: 'Product lifecycle management: engineering change orders with approval flow, item revisions, affected bills of material of manufacturing')]
#[OA\Response(response: 'PlmUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlmForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlmNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'PlmUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'PlmChangeOrder', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'reason', type: 'string'),
    new OA\Property(property: 'change_type', type: 'string', enum: ['design', 'material', 'process', 'documentation']),
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'critical']),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'in_review', 'approved', 'rejected', 'implemented', 'cancelled']),
    new OA\Property(property: 'requested_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'implemented_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'rejection_reason', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'approvals', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'approver_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'approver_name', type: 'string', nullable: true), new OA\Property(property: 'sequence', type: 'integer'),
        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'approved', 'rejected']),
        new OA\Property(property: 'decided_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'comment', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'affected_items', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'item_code', type: 'string', nullable: true), new OA\Property(property: 'item_name', type: 'string', nullable: true),
        new OA\Property(property: 'to_revision', type: 'string'), new OA\Property(property: 'change_summary', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'affected_boms', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'bom_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'bom_number', type: 'string', nullable: true), new OA\Property(property: 'bom_name', type: 'string', nullable: true),
        new OA\Property(property: 'bom_revision', type: 'string', nullable: true), new OA\Property(property: 'bom_status', type: 'string', nullable: true),
        new OA\Property(property: 'note', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'PlmChangeOrderRequest', type: 'object', required: ['code', 'title', 'reason'], properties: [
    new OA\Property(property: 'code', description: 'Unique in the company', type: 'string', maxLength: 40),
    new OA\Property(property: 'title', type: 'string', maxLength: 160),
    new OA\Property(property: 'reason', type: 'string'),
    new OA\Property(property: 'change_type', type: 'string', enum: ['design', 'material', 'process', 'documentation']),
    new OA\Property(property: 'priority', type: 'string', enum: ['low', 'normal', 'high', 'critical']),
    new OA\Property(property: 'effective_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'approvers', description: 'Users of the company in the order they decide', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    new OA\Property(property: 'affected_items', type: 'array', items: new OA\Items(type: 'object', required: ['mfg_item_id', 'to_revision'], properties: [
        new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'), new OA\Property(property: 'to_revision', type: 'string', maxLength: 20),
        new OA\Property(property: 'change_summary', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'affected_boms', type: 'array', items: new OA\Items(type: 'object', required: ['bom_id'], properties: [
        new OA\Property(property: 'bom_id', description: 'Bill of material of the manufacturing module', type: 'string', format: 'uuid'), new OA\Property(property: 'note', type: 'string', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'PlmDecisionRequest', type: 'object', required: ['decision'], properties: [
    new OA\Property(property: 'decision', type: 'string', enum: ['approve', 'reject']),
    new OA\Property(property: 'comment', description: 'Required to reject', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'PlmItemRevision', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'mfg_item_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'item', type: 'object', nullable: true, properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'item_code', type: 'string'), new OA\Property(property: 'name', type: 'string')]),
    new OA\Property(property: 'revision', type: 'string', example: 'B'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'released', 'obsolete']),
    new OA\Property(property: 'change_summary', type: 'string', nullable: true),
    new OA\Property(property: 'change_order_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'effective_from', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'released_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'obsoleted_at', type: 'string', format: 'date-time', nullable: true),
])]
#[OA\Schema(schema: 'PlmItemRevisionRequest', type: 'object', required: ['mfg_item_id', 'revision'], properties: [
    new OA\Property(property: 'mfg_item_id', description: 'Required on creation, fixed afterwards', type: 'string', format: 'uuid'),
    new OA\Property(property: 'revision', description: 'Unique per item', type: 'string', maxLength: 20),
    new OA\Property(property: 'change_summary', type: 'string', nullable: true),
    new OA\Property(property: 'effective_from', type: 'string', format: 'date', nullable: true),
])]
final class PlmOpenApi {}
