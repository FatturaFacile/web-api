<?php

declare(strict_types=1);

namespace Modules\Customs\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Response payloads of the customs endpoints, derived from their resources.
 */
#[OA\Schema(schema: 'CustomsRecordResponse', description: 'A customs record: its attributes, with decimals as strings', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'tenant_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'is_generic', description: 'Shared by every company and read-only', type: 'boolean'),
    ]),
])]
#[OA\Schema(schema: 'CustomsDeclarationResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'declaration_type', type: 'string', enum: ['import', 'export', 'transit']),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'validated', 'submitted', 'accepted', 'rejected', 'cancelled']),
        new OA\Property(property: 'trader_declaration_number', type: 'string'),
        new OA\Property(property: 'mrn', type: 'string', nullable: true),
        new OA\Property(property: 'mrn_version', type: 'integer', nullable: true),
        new OA\Property(property: 'total_gross_mass', type: 'string'),
        new OA\Property(property: 'total_net_mass', type: 'string'),
        new OA\Property(property: 'total_statistical_value', type: 'string'),
        new OA\Property(property: 'total_vat_value', type: 'string'),
        new OA\Property(property: 'total_packages', type: 'integer'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
    ]),
])]
#[OA\Schema(schema: 'CustomsValidationResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'field', type: 'string'), new OA\Property(property: 'message', type: 'string')])),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'string')),
    ]),
])]
#[OA\Schema(schema: 'CustomsAssessmentDecisionResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'mrn', type: 'string'),
        new OA\Property(property: 'version', type: 'integer'),
        new OA\Property(property: 'document_type', type: 'string'),
        new OA\Property(property: 'direction', type: 'string'),
        new OA\Property(property: 'document_date', type: 'string', format: 'date'),
        new OA\Property(property: 'duty_amount', type: 'string'),
        new OA\Property(property: 'vat_amount', type: 'string'),
        new OA\Property(property: 'xml_sha256', type: 'string'),
        new OA\Property(property: 'signature_status', type: 'string', enum: ['verified', 'invalid', 'not_signed', 'unsupported']),
        new OA\Property(property: 'signature_report', type: 'object', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'CustomsIntegrityResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'is_intact', type: 'boolean'),
        new OA\Property(property: 'files', type: 'object'),
    ]),
])]
#[OA\Schema(schema: 'CustomsControlListResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'date', type: 'string', format: 'date'),
        new OA\Property(property: 'summary', type: 'object'),
        new OA\Property(property: 'decisions', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'mrn', type: 'string'),
            new OA\Property(property: 'flags', type: 'array', items: new OA\Items(type: 'string', enum: ['signature_invalid', 'signature_unchecked', 'file_altered', 'schema_mismatch', 'no_declaration', 'not_posted'])),
        ])),
        new OA\Property(property: 'runs', type: 'array', items: new OA\Items(type: 'object')),
    ]),
])]
#[OA\Schema(schema: 'CustomsDossierResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'dossier_number', type: 'string'),
        new OA\Property(property: 'status', type: 'string'),
        new OA\Property(property: 'customer_contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'service_type', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
    ]),
])]
#[OA\Schema(schema: 'CustomsProfitabilityResponse', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'revenue', type: 'string'),
        new OA\Property(property: 'direct_costs', type: 'string'),
        new OA\Property(property: 'indirect_costs', type: 'string'),
        new OA\Property(property: 'contribution_margin', type: 'string'),
        new OA\Property(property: 'result', type: 'string'),
        new OA\Property(property: 'margin_percent', type: 'string'),
    ]),
])]
final class CustomsResponseSchemas {}
