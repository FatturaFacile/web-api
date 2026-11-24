<?php

declare(strict_types=1);

namespace Modules\Associations\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Request and response payloads of the Law 398/1991 regime, sports work, sections and the network statements.
 */
#[OA\Schema(schema: 'AssociationLex398Monitor', properties: [
    new OA\Property(property: 'enabled', type: 'boolean'),
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'commercial_income', type: 'string'),
    new OA\Property(property: 'ceiling', description: 'Legal parameter of the year; null when not configured', type: 'string', nullable: true),
    new OA\Property(property: 'warning_percent', type: 'string', nullable: true),
    new OA\Property(property: 'used_percent', type: 'string', nullable: true),
    new OA\Property(property: 'remaining', type: 'string', nullable: true),
    new OA\Property(property: 'status', type: 'string', enum: ['not_configured', 'within_ceiling', 'warning', 'exceeded']),
    new OA\Property(property: 'source', type: 'string', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationLex398Register', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'entries', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssociationRecord')),
    new OA\Property(property: 'by_rate', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'vat_rate', type: 'string', nullable: true),
        new OA\Property(property: 'taxable', type: 'string'),
        new OA\Property(property: 'vat', type: 'string'),
    ], type: 'object')),
    new OA\Property(property: 'totals', type: 'object', additionalProperties: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationLex398EntryRequest', required: ['entry_date', 'document_number', 'taxable_amount'], properties: [
    new OA\Property(property: 'entry_date', type: 'string', format: 'date'),
    new OA\Property(property: 'document_number', type: 'string'),
    new OA\Property(property: 'counterparty', type: 'string', nullable: true),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'taxable_amount', description: 'Negative for a correction', type: 'string'),
    new OA\Property(property: 'vat_rate', type: 'number', nullable: true),
    new OA\Property(property: 'vat_amount', type: 'string'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationSportCompensationRequest', required: ['collaborator_id', 'period', 'paid_on', 'gross_amount'], properties: [
    new OA\Property(property: 'collaborator_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'contract_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'period', type: 'string', example: '2026-10'),
    new OA\Property(property: 'paid_on', type: 'string', format: 'date'),
    new OA\Property(property: 'gross_amount', type: 'string'),
    new OA\Property(property: 'contribution_base', type: 'string', nullable: true),
    new OA\Property(property: 'contribution_amount', type: 'string', nullable: true),
    new OA\Property(property: 'withholding_base', type: 'string', nullable: true),
    new OA\Property(property: 'withholding_amount', type: 'string', nullable: true),
    new OA\Property(property: 'hours', type: 'number', nullable: true),
    new OA\Property(property: 'payment_method', type: 'string', enum: ['cash', 'bank'], nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
], type: 'object')]
#[OA\Schema(schema: 'AssociationSportThresholds', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'thresholds', type: 'object', additionalProperties: true),
    new OA\Property(property: 'people', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'collaborator_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'gross', type: 'string'),
        new OA\Property(property: 'contributions', type: 'string'),
        new OA\Property(property: 'withholdings', type: 'string'),
        new OA\Property(property: 'contribution_threshold', type: 'object', additionalProperties: true),
        new OA\Property(property: 'tax_threshold', type: 'object', additionalProperties: true),
    ], type: 'object')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationSportExpiries', properties: [
    new OA\Property(property: 'insurance', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssociationRecord')),
    new OA\Property(property: 'medical_certificates_expiring', type: 'integer'),
    new OA\Property(property: 'medical_certificates_expired', type: 'integer'),
], type: 'object')]
#[OA\Schema(schema: 'AssociationSectionReport', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'sections', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'section_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'revenues', type: 'string'),
        new OA\Property(property: 'costs', type: 'string'),
        new OA\Property(property: 'result', type: 'string'),
    ], type: 'object')),
    new OA\Property(property: 'not_assigned', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
    new OA\Property(property: 'consolidated', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
], type: 'object')]
#[OA\Schema(schema: 'AssociationNetworkStatements', properties: [
    new OA\Property(property: 'year', type: 'integer'),
    new OA\Property(property: 'affiliates', type: 'array', items: new OA\Items(properties: [
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'statement', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
        new OA\Property(property: 'compliance', type: 'object', additionalProperties: true),
    ], type: 'object')),
    new OA\Property(property: 'totals', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string')),
], type: 'object')]
final class RegimesOpenApiSchemas {}
