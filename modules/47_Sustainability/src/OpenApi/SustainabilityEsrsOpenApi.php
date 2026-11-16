<?php

declare(strict_types=1);

namespace Modules\Sustainability\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Records and payloads of the ESRS reporting package, Scope 3 and supplier data request endpoints.
 */
#[OA\Schema(schema: 'SustainabilityEsrsDatapoint', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'E1-6-SCOPE1'),
    new OA\Property(property: 'standard', type: 'string', enum: ['ESRS2', 'E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1']),
    new OA\Property(property: 'disclosure', type: 'string', example: 'E1-6'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'data_type', type: 'string', enum: ['number', 'text', 'boolean']),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'is_mandatory', type: 'boolean'),
    new OA\Property(property: 'auto_source', type: 'string', nullable: true, enum: ['inventory.scope_1_tco2e', 'inventory.scope_2_tco2e', 'inventory.scope_3_tco2e', 'inventory.total_tco2e', 'inventory.intensity_revenue', 'period.employees_fte']),
    new OA\Property(property: 'sort_order', type: 'integer'),
    new OA\Property(property: 'is_active', type: 'boolean'),
])]
#[OA\Schema(schema: 'SustainabilityEsrsDatapointRequest', type: 'object', required: ['code', 'standard', 'disclosure', 'name'], properties: [
    new OA\Property(property: 'code', description: 'Only on creation', type: 'string', maxLength: 40),
    new OA\Property(property: 'standard', type: 'string', enum: ['ESRS2', 'E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1']),
    new OA\Property(property: 'disclosure', type: 'string', maxLength: 20),
    new OA\Property(property: 'name', type: 'string', maxLength: 255),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'data_type', type: 'string', enum: ['number', 'text', 'boolean'], nullable: true),
    new OA\Property(property: 'unit', type: 'string', nullable: true),
    new OA\Property(property: 'is_mandatory', type: 'boolean', nullable: true),
    new OA\Property(property: 'auto_source', type: 'string', nullable: true),
    new OA\Property(property: 'sort_order', type: 'integer', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityEsrsValue', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'datapoint_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'reviewed', 'approved', 'not_applicable']),
    new OA\Property(property: 'value_number', type: 'string', nullable: true),
    new OA\Property(property: 'value_text', type: 'string', nullable: true),
    new OA\Property(property: 'value_boolean', type: 'boolean', nullable: true),
    new OA\Property(property: 'source', type: 'string', enum: ['manual', 'inventory']),
    new OA\Property(property: 'omission_reason', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityEsrsValuesRequest', type: 'object', required: ['values'], properties: [
    new OA\Property(property: 'values', type: 'array', items: new OA\Items(type: 'object', required: ['datapoint_id'], properties: [
        new OA\Property(property: 'datapoint_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'status', type: 'string', enum: ['draft', 'reviewed', 'approved', 'not_applicable'], nullable: true),
        new OA\Property(property: 'value_number', type: 'number', nullable: true),
        new OA\Property(property: 'value_text', type: 'string', nullable: true),
        new OA\Property(property: 'value_boolean', type: 'boolean', nullable: true),
        new OA\Property(property: 'omission_reason', type: 'string', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
    ])),
])]
#[OA\Schema(schema: 'SustainabilityEsrsPackage', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'object', properties: [
        new OA\Property(property: 'period_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'materiality', type: 'object', properties: [
            new OA\Property(property: 'assessment_id', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'material_standards', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'assessed_standards', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'unassessed_standards', type: 'array', items: new OA\Items(type: 'string')),
        ]),
        new OA\Property(property: 'datapoints', type: 'array', items: new OA\Items(allOf: [
            new OA\Schema(ref: '#/components/schemas/SustainabilityEsrsDatapoint'),
            new OA\Schema(type: 'object', properties: [
                new OA\Property(property: 'is_required', type: 'boolean'),
                new OA\Property(property: 'value', ref: '#/components/schemas/SustainabilityEsrsValue', nullable: true),
            ]),
        ])),
    ]),
])]
#[OA\Schema(schema: 'SustainabilityEsrsCompleteness', type: 'object', properties: [
    new OA\Property(property: 'period_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'is_complete', type: 'boolean'),
    new OA\Property(property: 'percent', type: 'string', example: '85.71'),
    new OA\Property(property: 'required', type: 'integer'),
    new OA\Property(property: 'complete', type: 'integer'),
    new OA\Property(property: 'materiality_assessment_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'standards', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'standard', type: 'string'),
        new OA\Property(property: 'is_material', type: 'boolean'),
        new OA\Property(property: 'required', type: 'integer'),
        new OA\Property(property: 'complete', type: 'integer'),
        new OA\Property(property: 'percent', type: 'string'),
    ])),
    new OA\Property(property: 'missing', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'datapoint_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'code', type: 'string'),
        new OA\Property(property: 'standard', type: 'string'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'reason', type: 'string', enum: ['no_value', 'not_approved', 'omission_reason_missing']),
    ])),
    new OA\Property(property: 'issues', description: 'Blocking issues: catalog_empty, materiality_assessment_missing, topic_not_assessed:<standard>, datapoints_missing', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'warnings', description: 'scope3_categories_without_data, supplier_requests_open, period_open', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'scope3_categories_without_data', type: 'array', items: new OA\Items(type: 'integer')),
    new OA\Property(property: 'open_supplier_requests', type: 'integer'),
])]
#[OA\Schema(schema: 'SustainabilityScope3Report', type: 'object', properties: [
    new OA\Property(property: 'period_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'total_kg', type: 'string'),
    new OA\Property(property: 'total_tco2e', type: 'string'),
    new OA\Property(property: 'supplier_specific_percent', type: 'string'),
    new OA\Property(property: 'categories_with_data', type: 'integer'),
    new OA\Property(property: 'unmapped_kg', type: 'string'),
    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'category', type: 'integer', minimum: 1, maximum: 15),
        new OA\Property(property: 'key', type: 'string', example: 'purchased_goods_services'),
        new OA\Property(property: 'co2e_kg', type: 'string'),
        new OA\Property(property: 'share_percent', type: 'string'),
        new OA\Property(property: 'records', type: 'integer'),
        new OA\Property(property: 'supplier_specific_kg', type: 'string'),
        new OA\Property(property: 'supplier_specific_percent', type: 'string'),
        new OA\Property(property: 'estimated_percent', type: 'string'),
        new OA\Property(property: 'open_supplier_requests', type: 'integer'),
        new OA\Property(property: 'has_data', type: 'boolean'),
    ])),
])]
#[OA\Schema(schema: 'SustainabilitySupplierRequest', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'reporting_period_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_name', type: 'string', nullable: true),
    new OA\Property(property: 'document_requirement_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'submission_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'scope3_category', type: 'integer', minimum: 1, maximum: 15),
    new OA\Property(property: 'activity_type', type: 'string'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'quantity', type: 'string'),
    new OA\Property(property: 'status', type: 'string', enum: ['requested', 'received', 'applied', 'declined', 'cancelled']),
    new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'reported_co2e_kg', type: 'string', nullable: true),
    new OA\Property(property: 'calculation_method', type: 'string', nullable: true),
    new OA\Property(property: 'emission_factor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'activity_record_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'applied_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilitySupplierRequestPayload', type: 'object', required: ['reporting_period_id', 'supplier_id', 'scope3_category', 'activity_type', 'unit', 'quantity'], properties: [
    new OA\Property(property: 'reporting_period_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'supplier_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'scope3_category', type: 'integer', minimum: 1, maximum: 15),
    new OA\Property(property: 'activity_type', description: 'A Scope 3 activity type whose category matches scope3_category', type: 'string'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'quantity', description: 'Quantity purchased from the supplier in the period', type: 'string'),
    new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilitySupplierReceivePayload', type: 'object', required: ['reported_co2e_kg'], properties: [
    new OA\Property(property: 'reported_co2e_kg', description: 'Emissions the supplier attributes to the requested quantity', type: 'string'),
    new OA\Property(property: 'calculation_method', type: 'string', nullable: true),
    new OA\Property(property: 'submission_id', description: 'Supplier portal document submission carrying the statement', type: 'string', format: 'uuid', nullable: true),
])]
final class SustainabilityEsrsOpenApi {}
