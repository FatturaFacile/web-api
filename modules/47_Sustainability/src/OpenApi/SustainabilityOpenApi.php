<?php

declare(strict_types=1);

namespace Modules\Sustainability\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the ESG and carbon accounting endpoints.
 */
#[OA\Tag(name: 'Sustainability', description: 'ESG and carbon accounting (GHG Protocol, CSRD/ESRS): emission factors, activity data, reporting periods and inventory, reduction targets, ESG KPIs and double materiality')]
#[OA\Response(response: 'SustainabilityUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SustainabilityForbidden', description: 'The role of the user does not allow this', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SustainabilityNotFound', description: 'Record not found in the active company', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'SustainabilityUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'SustainabilityFactor', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string', example: 'EX-ELEC-IT'),
    new OA\Property(property: 'version', type: 'integer', example: 1),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'activity_type', type: 'string', enum: ['electricity_grid', 'natural_gas', 'fuel_diesel', 'fuel_petrol', 'business_travel_car', 'business_travel_air', 'business_travel_rail', 'purchased_goods', 'waste', 'water', 'capital_goods', 'fuel_energy_related', 'freight_road', 'freight_sea', 'freight_air', 'employee_commuting_car', 'employee_commuting_transit', 'upstream_leased_assets', 'downstream_freight', 'processing_sold_products', 'use_of_sold_products', 'end_of_life_sold_products', 'downstream_leased_assets', 'franchises', 'investments']),
    new OA\Property(property: 'unit', type: 'string', enum: ['kWh', 'm3', 'l', 'km', 'EUR', 'CHF', 'kg', 'tkm']),
    new OA\Property(property: 'scope', type: 'integer', enum: [1, 2, 3]),
    new OA\Property(property: 'category', type: 'string', example: 'purchased_electricity'),
    new OA\Property(property: 'kg_co2e_per_unit', description: 'Decimal string, 8 decimals', type: 'string', example: '0.25000000'),
    new OA\Property(property: 'region', type: 'string', enum: ['IT', 'CH', 'EU', 'GLOBAL']),
    new OA\Property(property: 'source', type: 'string'),
    new OA\Property(property: 'source_year', type: 'integer', example: 2023),
    new OA\Property(property: 'is_example', description: 'An indicative example value to be replaced with the official source', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilityFactorRequest', type: 'object', required: ['code', 'name', 'activity_type', 'unit', 'kg_co2e_per_unit', 'source', 'source_year'], properties: [
    new OA\Property(property: 'code', description: 'Unique in the company and fixed after creation; new versions come from new-version', type: 'string', maxLength: 60),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'activity_type', type: 'string'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'kg_co2e_per_unit', type: 'string', example: '0.25'),
    new OA\Property(property: 'region', type: 'string', enum: ['IT', 'CH', 'EU', 'GLOBAL'], nullable: true),
    new OA\Property(property: 'source', type: 'string', maxLength: 190),
    new OA\Property(property: 'source_year', type: 'integer'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityFactorVersionRequest', type: 'object', required: ['kg_co2e_per_unit', 'source', 'source_year'], properties: [
    new OA\Property(property: 'kg_co2e_per_unit', type: 'string', example: '0.24'),
    new OA\Property(property: 'source', type: 'string', maxLength: 190),
    new OA\Property(property: 'source_year', type: 'integer'),
])]
#[OA\Schema(schema: 'SustainabilityPeriod', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'status', type: 'string', enum: ['open', 'closed']),
    new OA\Property(property: 'revenue', type: 'string', nullable: true, example: '2500000.00'),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF']),
    new OA\Property(property: 'employees_fte', type: 'string', nullable: true, example: '42.50'),
    new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'closed_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilityPeriodRequest', type: 'object', required: ['name', 'starts_on', 'ends_on'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'FY 2026'),
    new OA\Property(property: 'starts_on', type: 'string', format: 'date'),
    new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
    new OA\Property(property: 'revenue', type: 'string', nullable: true),
    new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF'], nullable: true),
    new OA\Property(property: 'employees_fte', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityActivityRecord', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'emission_factor_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'activity_date', type: 'string', format: 'date'),
    new OA\Property(property: 'quantity', type: 'string', example: '1200.0000'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'activity_type', type: 'string'),
    new OA\Property(property: 'scope', type: 'integer', enum: [1, 2, 3]),
    new OA\Property(property: 'category', type: 'string'),
    new OA\Property(property: 'factor_code', description: 'Frozen factor code', type: 'string'),
    new OA\Property(property: 'factor_version', description: 'Frozen factor version', type: 'integer'),
    new OA\Property(property: 'factor_kg_co2e_per_unit', description: 'Frozen factor value', type: 'string'),
    new OA\Property(property: 'factor_source', type: 'string'),
    new OA\Property(property: 'co2e_kg', description: 'quantity x factor, 4 decimals', type: 'string', example: '300.0000'),
    new OA\Property(property: 'data_quality', type: 'string', enum: ['measured', 'estimated']),
    new OA\Property(property: 'site', type: 'string', nullable: true),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'source_type', type: 'string', nullable: true),
    new OA\Property(property: 'source_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'recorded_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilityActivityRecordRequest', description: 'Name the factor (emission_factor_id) or the activity_type (and optionally the region).', type: 'object', required: ['activity_date', 'quantity', 'unit'], properties: [
    new OA\Property(property: 'emission_factor_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'activity_type', type: 'string', nullable: true),
    new OA\Property(property: 'region', type: 'string', enum: ['IT', 'CH', 'EU', 'GLOBAL'], nullable: true),
    new OA\Property(property: 'activity_date', type: 'string', format: 'date'),
    new OA\Property(property: 'quantity', type: 'string', example: '1200'),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'data_quality', type: 'string', enum: ['measured', 'estimated'], nullable: true),
    new OA\Property(property: 'site', type: 'string', nullable: true),
    new OA\Property(property: 'reference', type: 'string', nullable: true),
    new OA\Property(property: 'source_type', type: 'string', nullable: true),
    new OA\Property(property: 'source_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityBulkActivityRequest', type: 'object', required: ['lines'], properties: [
    new OA\Property(property: 'lines', type: 'array', minItems: 1, maxItems: 500, items: new OA\Items(ref: '#/components/schemas/SustainabilityActivityRecordRequest')),
])]
#[OA\Schema(schema: 'SustainabilityInventory', type: 'object', properties: [
    new OA\Property(property: 'period', type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'starts_on', type: 'string', format: 'date'), new OA\Property(property: 'ends_on', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string'),
    ]),
    new OA\Property(property: 'totals', type: 'object', properties: [
        new OA\Property(property: 'scope_1_kg', type: 'string'), new OA\Property(property: 'scope_2_kg', type: 'string'),
        new OA\Property(property: 'scope_3_kg', type: 'string'), new OA\Property(property: 'total_kg', type: 'string'),
        new OA\Property(property: 'total_tco2e', type: 'string'),
    ]),
    new OA\Property(property: 'scopes', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'scope', type: 'integer'), new OA\Property(property: 'co2e_kg', type: 'string'),
        new OA\Property(property: 'tco2e', type: 'string'), new OA\Property(property: 'share_percent', type: 'string'),
        new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object', properties: [
            new OA\Property(property: 'category', type: 'string'), new OA\Property(property: 'co2e_kg', type: 'string'),
            new OA\Property(property: 'share_percent', type: 'string'), new OA\Property(property: 'records', type: 'integer'),
        ])),
    ])),
    new OA\Property(property: 'data_quality', type: 'object', properties: [
        new OA\Property(property: 'records', type: 'integer'), new OA\Property(property: 'estimated_records', type: 'integer'),
        new OA\Property(property: 'estimated_kg', type: 'string'), new OA\Property(property: 'estimated_share_percent', type: 'string'),
    ]),
    new OA\Property(property: 'intensity', type: 'object', properties: [
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'tco2e_per_million_revenue', type: 'string', nullable: true),
        new OA\Property(property: 'tco2e_per_fte', type: 'string', nullable: true),
    ]),
    new OA\Property(property: 'previous', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'period_id', type: 'string', format: 'uuid'), new OA\Property(property: 'period_name', type: 'string'),
        new OA\Property(property: 'totals', type: 'object'),
        new OA\Property(property: 'delta', description: 'scope_1, scope_2, scope_3 and total: absolute_kg and percent (null when the previous value is zero)', type: 'object'),
    ]),
])]
#[OA\Schema(schema: 'SustainabilityCatalog', type: 'object', properties: [
    new OA\Property(property: 'activity_types', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'activity_type', type: 'string'), new OA\Property(property: 'scope', type: 'integer'),
        new OA\Property(property: 'category', type: 'string'), new OA\Property(property: 'units', type: 'array', items: new OA\Items(type: 'string')),
    ])),
    new OA\Property(property: 'regions', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'data_qualities', type: 'array', items: new OA\Items(type: 'string')),
])]
#[OA\Schema(schema: 'SustainabilityTarget', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'scope', type: 'string', enum: ['scope_1', 'scope_2', 'scope_3', 'total']),
    new OA\Property(property: 'baseline_year', type: 'integer'),
    new OA\Property(property: 'baseline_co2e_kg', type: 'string', nullable: true),
    new OA\Property(property: 'target_year', type: 'integer'),
    new OA\Property(property: 'reduction_percent', type: 'string', example: '42.00'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'active', 'achieved', 'missed', 'cancelled']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'activated_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'evaluated_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'progress', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'baseline_co2e_kg', type: 'string'), new OA\Property(property: 'target_co2e_kg', type: 'string'),
        new OA\Property(property: 'current_year', type: 'integer', nullable: true), new OA\Property(property: 'current_co2e_kg', type: 'string', nullable: true),
        new OA\Property(property: 'reduction_achieved_percent', type: 'string', nullable: true), new OA\Property(property: 'expected_reduction_percent', type: 'string', nullable: true),
        new OA\Property(property: 'progress_percent', type: 'string', nullable: true), new OA\Property(property: 'on_track', type: 'boolean', nullable: true),
    ]),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilityTargetRequest', type: 'object', required: ['name', 'scope', 'baseline_year', 'target_year', 'reduction_percent'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'scope', type: 'string', enum: ['scope_1', 'scope_2', 'scope_3', 'total']),
    new OA\Property(property: 'baseline_year', type: 'integer'),
    new OA\Property(property: 'target_year', type: 'integer'),
    new OA\Property(property: 'reduction_percent', type: 'string', example: '42'),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityKpiDefinition', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'code', type: 'string'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'pillar', type: 'string', enum: ['environmental', 'social', 'governance']),
    new OA\Property(property: 'unit', type: 'string'),
    new OA\Property(property: 'direction', type: 'string', enum: ['higher_is_better', 'lower_is_better']),
    new OA\Property(property: 'esrs_reference', type: 'string', nullable: true),
    new OA\Property(property: 'target_value', type: 'string', nullable: true),
    new OA\Property(property: 'is_example', type: 'boolean'),
    new OA\Property(property: 'is_active', type: 'boolean'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilityKpiDefinitionRequest', type: 'object', required: ['code', 'name', 'pillar', 'unit'], properties: [
    new OA\Property(property: 'code', description: 'Lower case letters, digits and underscores; fixed after creation', type: 'string', maxLength: 60),
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'pillar', type: 'string', enum: ['environmental', 'social', 'governance']),
    new OA\Property(property: 'unit', type: 'string', maxLength: 20),
    new OA\Property(property: 'direction', type: 'string', enum: ['higher_is_better', 'lower_is_better'], nullable: true),
    new OA\Property(property: 'esrs_reference', type: 'string', nullable: true),
    new OA\Property(property: 'target_value', type: 'string', nullable: true),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityKpiValue', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'kpi_definition_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'reporting_period_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'value', type: 'string', example: '12.5000'),
    new OA\Property(property: 'data_quality', type: 'string', enum: ['measured', 'estimated']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'recorded_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'kpi', ref: '#/components/schemas/SustainabilityKpiDefinition'),
    new OA\Property(property: 'target_met', type: 'boolean', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilityKpiValueRequest', type: 'object', required: ['kpi_definition_id', 'reporting_period_id', 'value'], properties: [
    new OA\Property(property: 'kpi_definition_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'reporting_period_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'value', type: 'string', example: '12.5'),
    new OA\Property(property: 'data_quality', type: 'string', enum: ['measured', 'estimated'], nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
])]
#[OA\Schema(schema: 'SustainabilityMateriality', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'reporting_period_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'threshold', type: 'string', example: '3.00'),
    new OA\Property(property: 'status', type: 'string', enum: ['draft', 'approved']),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
    new OA\Property(property: 'approved_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'topics', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'esrs_code', type: 'string', enum: ['E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1']),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'impact_score', type: 'string'),
        new OA\Property(property: 'financial_score', type: 'string'),
        new OA\Property(property: 'is_material', type: 'boolean'),
        new OA\Property(property: 'materiality_type', type: 'string', enum: ['both', 'impact', 'financial', 'none']),
        new OA\Property(property: 'rationale', type: 'string', nullable: true),
    ])),
    new OA\Property(property: 'material_topics_count', type: 'integer'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'SustainabilityMaterialityRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 190),
    new OA\Property(property: 'reporting_period_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'threshold', description: 'From 0.5 to 5, default 3', type: 'string', nullable: true),
    new OA\Property(property: 'notes', type: 'string', nullable: true),
    new OA\Property(property: 'topics', type: 'array', items: new OA\Items(type: 'object', required: ['esrs_code', 'name', 'impact_score', 'financial_score'], properties: [
        new OA\Property(property: 'esrs_code', type: 'string', enum: ['E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1']),
        new OA\Property(property: 'name', type: 'string', maxLength: 190),
        new OA\Property(property: 'impact_score', description: '0 to 5', type: 'string'),
        new OA\Property(property: 'financial_score', description: '0 to 5', type: 'string'),
        new OA\Property(property: 'rationale', type: 'string', nullable: true),
    ])),
])]
final class SustainabilityOpenApi {}
