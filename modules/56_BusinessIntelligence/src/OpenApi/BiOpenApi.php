<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Responses, payloads and records of the business intelligence endpoints.
 */
#[OA\Tag(name: 'Business Intelligence', description: 'Business intelligence on the semantic layer: datasets with calculated measures, saved pivot queries, configurable dashboards with widgets, drill-down and filters, sharing and snapshots. Queries never carry SQL: every field must be declared by the dataset, and every query is limited to the active company and to the datasets of the modules the caller can read.')]
#[OA\Response(response: 'BiUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BiForbidden', description: 'The role of the user does not allow this, or the dataset belongs to a module the user cannot read', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BiNotFound', description: 'Record not found in the active company, or not visible to the caller', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'BiUnprocessable', description: 'Validation error or business rule violation', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
#[OA\Schema(schema: 'BiDataset', type: 'object', properties: [
    new OA\Property(property: 'key', type: 'string', example: 'sales'),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'description', type: 'string'),
    new OA\Property(property: 'module', type: 'string'),
    new OA\Property(property: 'supports_period', type: 'boolean'),
    new OA\Property(property: 'period_dimension', type: 'string', nullable: true),
    new OA\Property(property: 'dimensions', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'type', type: 'string', enum: ['string', 'number', 'date', 'boolean']),
        new OA\Property(property: 'operators', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'granularities', type: 'array', items: new OA\Items(type: 'string')),
    ])),
    new OA\Property(property: 'measures', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'unit', type: 'string', enum: ['currency', 'count', 'quantity', 'percent']),
    ])),
    new OA\Property(property: 'calculated_measures', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'key', type: 'string'),
        new OA\Property(property: 'label', type: 'string'),
        new OA\Property(property: 'unit', type: 'string'),
        new OA\Property(property: 'formula', type: 'string', example: 'net_amount / document_count'),
    ])),
])]
#[OA\Schema(schema: 'BiCalculatedMeasure', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'dataset', type: 'string'),
    new OA\Property(property: 'key', type: 'string'),
    new OA\Property(property: 'label', type: 'string'),
    new OA\Property(property: 'formula', type: 'string'),
    new OA\Property(property: 'unit', type: 'string', enum: ['number', 'currency', 'count', 'quantity', 'percent']),
    new OA\Property(property: 'scale', type: 'integer'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'BiCalculatedMeasureRequest', type: 'object', required: ['dataset', 'key', 'label', 'formula'], properties: [
    new OA\Property(property: 'dataset', description: 'Only on creation', type: 'string', example: 'sales'),
    new OA\Property(property: 'key', description: 'Only on creation; lower case letters, digits and underscores', type: 'string', example: 'average_ticket'),
    new OA\Property(property: 'label', type: 'string', maxLength: 120),
    new OA\Property(property: 'formula', type: 'string', maxLength: 500, example: 'net_amount / document_count'),
    new OA\Property(property: 'unit', type: 'string', enum: ['number', 'currency', 'count', 'quantity', 'percent']),
    new OA\Property(property: 'scale', description: 'Decimals of the result', type: 'integer', minimum: 0, maximum: 6),
])]
#[OA\Schema(schema: 'BiPivotLayout', type: 'object', properties: [
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'string'), description: 'Dimension keys of the query'),
    new OA\Property(property: 'columns', type: 'array', items: new OA\Items(type: 'string'), description: 'Dimension keys of the query, at most two'),
    new OA\Property(property: 'values', type: 'array', items: new OA\Items(type: 'string'), description: 'Measure keys of the query, calculated ones included'),
], nullable: true)]
#[OA\Schema(schema: 'BiSavedQuery', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'owner_name', type: 'string', nullable: true),
    new OA\Property(property: 'is_owner', type: 'boolean'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'dataset', type: 'string'),
    new OA\Property(property: 'spec', description: 'Specification of the semantic layer: dimensions, measures, filters, sort, limit, period, compare', type: 'object'),
    new OA\Property(property: 'calculated_measures', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'pivot', ref: '#/components/schemas/BiPivotLayout', nullable: true),
    new OA\Property(property: 'visibility', type: 'string', enum: ['private', 'company', 'shared']),
    new OA\Property(property: 'snapshot_frequency', type: 'string', enum: ['daily', 'weekly', 'monthly'], nullable: true),
    new OA\Property(property: 'next_snapshot_on', type: 'string', format: 'date', nullable: true),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'BiSavedQueryRequest', type: 'object', required: ['name', 'dataset', 'spec'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'dataset', type: 'string', example: 'sales'),
    new OA\Property(property: 'spec', type: 'object', example: ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'period' => ['preset' => 'year_to_date']]),
    new OA\Property(property: 'calculated_measures', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'pivot', ref: '#/components/schemas/BiPivotLayout', nullable: true),
    new OA\Property(property: 'visibility', description: 'private or company; shared is derived from the shares', type: 'string', enum: ['private', 'company']),
    new OA\Property(property: 'snapshot_frequency', type: 'string', enum: ['daily', 'weekly', 'monthly'], nullable: true),
])]
#[OA\Schema(schema: 'BiExplorerRequest', type: 'object', required: ['dataset', 'spec'], properties: [
    new OA\Property(property: 'dataset', type: 'string'),
    new OA\Property(property: 'spec', type: 'object'),
    new OA\Property(property: 'calculated_measures', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'pivot', ref: '#/components/schemas/BiPivotLayout', nullable: true),
])]
#[OA\Schema(schema: 'BiQueryResult', type: 'object', properties: [
    new OA\Property(property: 'columns', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'meta', type: 'object'),
])]
#[OA\Schema(schema: 'BiPivotResult', type: 'object', nullable: true, properties: [
    new OA\Property(property: 'row_dimensions', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'column_dimensions', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'values', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'columns', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'column_totals', type: 'object'),
    new OA\Property(property: 'grand_totals', type: 'object'),
])]
#[OA\Schema(schema: 'BiQueryOutcome', type: 'object', properties: [
    new OA\Property(property: 'result', ref: '#/components/schemas/BiQueryResult'),
    new OA\Property(property: 'pivot', ref: '#/components/schemas/BiPivotResult'),
])]
#[OA\Schema(schema: 'BiWidget', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'dashboard_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'saved_query_id', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'title', type: 'string'),
    new OA\Property(property: 'widget_type', type: 'string', enum: ['kpi', 'table', 'bar', 'line', 'pie', 'pivot']),
    new OA\Property(property: 'dataset', type: 'string', nullable: true),
    new OA\Property(property: 'spec', type: 'object', nullable: true),
    new OA\Property(property: 'calculated_measures', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'config', type: 'object', description: 'drill_path (ordered non-date dimension keys) and, for a pivot widget, the pivot layout'),
    new OA\Property(property: 'layout', type: 'object', properties: [
        new OA\Property(property: 'x', type: 'integer'),
        new OA\Property(property: 'y', type: 'integer'),
        new OA\Property(property: 'w', type: 'integer'),
        new OA\Property(property: 'h', type: 'integer'),
    ]),
])]
#[OA\Schema(schema: 'BiWidgetRequest', type: 'object', required: ['title', 'widget_type'], properties: [
    new OA\Property(property: 'title', type: 'string', maxLength: 160),
    new OA\Property(property: 'widget_type', type: 'string', enum: ['kpi', 'table', 'bar', 'line', 'pie', 'pivot']),
    new OA\Property(property: 'saved_query_id', description: 'A saved query the caller can open; exclusive with dataset and spec', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'dataset', type: 'string', nullable: true),
    new OA\Property(property: 'spec', type: 'object', nullable: true),
    new OA\Property(property: 'calculated_measures', type: 'array', items: new OA\Items(type: 'string')),
    new OA\Property(property: 'config', type: 'object', nullable: true),
    new OA\Property(property: 'layout', description: 'x, y, w, h on the 12-column grid; without x and y the first free place is used', type: 'object', nullable: true),
])]
#[OA\Schema(schema: 'BiLayoutRequest', type: 'object', required: ['layout'], properties: [
    new OA\Property(property: 'layout', type: 'array', items: new OA\Items(type: 'object', required: ['id', 'x', 'y', 'w', 'h'], properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'x', type: 'integer'),
        new OA\Property(property: 'y', type: 'integer'),
        new OA\Property(property: 'w', type: 'integer'),
        new OA\Property(property: 'h', type: 'integer'),
    ])),
])]
#[OA\Schema(schema: 'BiWidgetData', type: 'object', properties: [
    new OA\Property(property: 'widget_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'status', type: 'string', enum: ['ok', 'error']),
    new OA\Property(property: 'error', type: 'string', nullable: true),
    new OA\Property(property: 'result', ref: '#/components/schemas/BiQueryResult', nullable: true),
    new OA\Property(property: 'pivot', ref: '#/components/schemas/BiPivotResult'),
    new OA\Property(property: 'drill', type: 'object', nullable: true, properties: [
        new OA\Property(property: 'path', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'level', type: 'integer'),
        new OA\Property(property: 'current_dimension', type: 'string', nullable: true),
        new OA\Property(property: 'next_dimension', type: 'string', nullable: true),
    ]),
])]
#[OA\Schema(schema: 'BiDashboard', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'owner_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'owner_name', type: 'string', nullable: true),
    new OA\Property(property: 'is_owner', type: 'boolean'),
    new OA\Property(property: 'name', type: 'string'),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'visibility', type: 'string', enum: ['private', 'company', 'shared']),
    new OA\Property(property: 'filters', type: 'object', description: 'period ({preset} or {from, to}) and filters ({field, operator, value}) applied to every widget whose dataset has the dimension'),
    new OA\Property(property: 'widgets', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiWidget')),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'BiDashboardRequest', type: 'object', required: ['name'], properties: [
    new OA\Property(property: 'name', type: 'string', maxLength: 160),
    new OA\Property(property: 'description', type: 'string', nullable: true),
    new OA\Property(property: 'visibility', type: 'string', enum: ['private', 'company']),
    new OA\Property(property: 'filters', type: 'object', nullable: true),
])]
#[OA\Schema(schema: 'BiShare', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'resource_type', type: 'string', enum: ['dashboard', 'saved_query']),
    new OA\Property(property: 'resource_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_name', type: 'string', nullable: true),
    new OA\Property(property: 'permission', type: 'string', enum: ['view', 'edit']),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'BiShareRequest', type: 'object', required: ['resource_type', 'resource_id', 'user_id', 'permission'], properties: [
    new OA\Property(property: 'resource_type', type: 'string', enum: ['dashboard', 'saved_query']),
    new OA\Property(property: 'resource_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'permission', type: 'string', enum: ['view', 'edit']),
])]
#[OA\Schema(schema: 'BiSnapshot', type: 'object', properties: [
    new OA\Property(property: 'id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'source_type', type: 'string', enum: ['saved_query', 'dashboard']),
    new OA\Property(property: 'source_id', type: 'string', format: 'uuid'),
    new OA\Property(property: 'taken_by', type: 'string', format: 'uuid', nullable: true),
    new OA\Property(property: 'taken_on', type: 'string', format: 'date'),
    new OA\Property(property: 'trigger', type: 'string', enum: ['manual', 'scheduled']),
    new OA\Property(property: 'status', type: 'string', enum: ['ok', 'failed']),
    new OA\Property(property: 'error', type: 'string', nullable: true),
    new OA\Property(property: 'row_count', type: 'integer'),
    new OA\Property(property: 'payload', description: 'Only in the detail response', type: 'object'),
    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
])]
#[OA\Schema(schema: 'BiSnapshotRequest', type: 'object', required: ['source_type', 'source_id'], properties: [
    new OA\Property(property: 'source_type', type: 'string', enum: ['saved_query', 'dashboard']),
    new OA\Property(property: 'source_id', type: 'string', format: 'uuid'),
])]
#[OA\Schema(schema: 'BiSnapshotComparison', type: 'object', properties: [
    new OA\Property(property: 'taken_on', type: 'string', format: 'date'),
    new OA\Property(property: 'columns', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object'), description: 'Dimension values plus, for each measure, {snapshot, current, change}'),
])]
#[OA\Schema(schema: 'BiOverview', type: 'object', properties: [
    new OA\Property(property: 'dashboards', type: 'integer'),
    new OA\Property(property: 'saved_queries', type: 'integer'),
    new OA\Property(property: 'scheduled_queries', type: 'integer'),
    new OA\Property(property: 'calculated_measures', type: 'integer'),
    new OA\Property(property: 'recent_dashboards', type: 'array', items: new OA\Items(type: 'object')),
    new OA\Property(property: 'failed_snapshots', type: 'array', items: new OA\Items(type: 'object')),
])]
final class BiOpenApi {}
