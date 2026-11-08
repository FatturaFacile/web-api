<?php

declare(strict_types=1);

namespace Modules\Analytics\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the reporting endpoints of the Analytics module.
 */
#[OA\Schema(
    schema: 'AnalyticsKpiResult',
    description: 'One indicator. `value` is null when there is no posted data for it, never zero. `details` depends on the indicator.',
    type: 'object',
    properties: [
        new OA\Property(property: 'key', type: 'string', enum: ['sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value', 'project-margin', 'hr-headcount'], example: 'sales'),
        new OA\Property(property: 'label', type: 'string', example: 'Net sales'),
        new OA\Property(property: 'unit', type: 'string', enum: ['currency', 'count', 'percent']),
        new OA\Property(property: 'kind', type: 'string', enum: ['period', 'snapshot'], description: 'Snapshot indicators describe today and ignore the dates of the report'),
        new OA\Property(property: 'available', type: 'boolean', description: 'False when the caller cannot read the module the indicator is drawn from'),
        new OA\Property(property: 'reason', type: 'string', nullable: true, enum: ['forbidden', 'snapshot_not_comparable']),
        new OA\Property(property: 'value', type: 'string', nullable: true, example: '12500.00'),
        new OA\Property(property: 'details', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'comparison', type: 'object', nullable: true, description: 'Period, value and details of the comparison period'),
        new OA\Property(property: 'change', type: 'string', nullable: true, example: '2500.00'),
        new OA\Property(property: 'change_percent', type: 'string', nullable: true, description: 'Null when the base is missing or zero', example: '25.00'),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsKpiReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'period', type: 'object', properties: [new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')]),
        new OA\Property(property: 'comparison_period', type: 'object', nullable: true, properties: [new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')]),
        new OA\Property(property: 'kpis', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsKpiResult')),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsReportDefinition',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', description: 'Owner'),
        new OA\Property(property: 'name', type: 'string', example: 'Monthly board pack'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'kpis', type: 'array', items: new OA\Items(type: 'string'), example: ['sales', 'margin']),
        new OA\Property(property: 'period_preset', type: 'string', enum: ['custom', 'month_to_date', 'last_month', 'quarter_to_date', 'year_to_date', 'last_30_days']),
        new OA\Property(property: 'from_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'to_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'compare', type: 'string', enum: ['none', 'previous_period', 'previous_year']),
        new OA\Property(property: 'is_shared', type: 'boolean'),
        new OA\Property(property: 'is_owner', type: 'boolean'),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsSaveReportDefinitionRequest',
    type: 'object',
    required: ['name', 'kpis', 'period_preset'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150, example: 'Monthly board pack'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'kpis', type: 'array', minItems: 1, items: new OA\Items(type: 'string', enum: ['sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value', 'project-margin', 'hr-headcount']), example: ['sales', 'margin']),
        new OA\Property(property: 'period_preset', type: 'string', enum: ['custom', 'month_to_date', 'last_month', 'quarter_to_date', 'year_to_date', 'last_30_days']),
        new OA\Property(property: 'from_date', type: 'string', format: 'date', nullable: true, description: 'Required with the custom preset'),
        new OA\Property(property: 'to_date', type: 'string', format: 'date', nullable: true, description: 'Required with the custom preset'),
        new OA\Property(property: 'compare', type: 'string', enum: ['none', 'previous_period', 'previous_year'], default: 'none'),
        new OA\Property(property: 'is_shared', type: 'boolean', default: false),
    ]
)]
final class AnalyticsSchemas {}
