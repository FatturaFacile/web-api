<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the association endpoints that clients read field by field.
 */
#[OA\Schema(
    schema: 'AssociationMember',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['candidate', 'active', 'inactive', 'ceased']),
        new OA\Property(property: 'first_name', type: 'string', nullable: true),
        new OA\Property(property: 'last_name', type: 'string', nullable: true),
        new OA\Property(property: 'email', type: 'string', nullable: true),
        new OA\Property(property: 'phone', type: 'string', nullable: true),
        new OA\Property(property: 'is_minor', type: 'boolean'),
        new OA\Property(property: 'birth_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'tax_code', type: 'string', nullable: true),
        new OA\Property(property: 'custom_fields', type: 'object', additionalProperties: true),
        new OA\Property(property: 'health_notes', description: 'Only with the health capability', type: 'string', nullable: true),
        new OA\Property(property: 'allergies', description: 'Only with the health capability', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationAccessDecision',
    type: 'object',
    properties: [
        new OA\Property(property: 'decision', type: 'string', enum: ['allow', 'deny']),
        new OA\Property(property: 'reason_code', type: 'string', example: 'fee_overdue'),
        new OA\Property(property: 'area', type: 'string'),
        new OA\Property(property: 'occupancy', type: 'integer'),
        new OA\Property(property: 'capacity', type: 'integer', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AssociationFeeStanding',
    type: 'object',
    properties: [
        new OA\Property(property: 'standing', type: 'string', enum: ['settled', 'attention', 'overdue']),
        new OA\Property(property: 'color', type: 'string', enum: ['green', 'orange', 'red']),
    ]
)]
#[OA\Schema(
    schema: 'AssociationNetworkFigures',
    type: 'object',
    properties: [
        new OA\Property(property: 'affiliates', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'figures', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
        ])),
        new OA\Property(property: 'totals', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'integer')),
    ]
)]
final class AssociationsResponseSchemas {}
