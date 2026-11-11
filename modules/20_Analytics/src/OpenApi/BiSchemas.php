<?php

declare(strict_types=1);

namespace Modules\Analytics\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Payloads of the self-service reporting endpoints. Kept apart from the parameters: in swagger-php the other attributes of
 * a class that carries a schema are read as part of that schema, and would be lost.
 */
#[OA\Schema(
    schema: 'AnalyticsReportSpec',
    description: 'What the designer chose. Every key is checked against the catalogue of the dataset: an unknown dimension, measure, operator or granularity is a 422. A filter may also name a measure (a HAVING condition on the aggregate): only eq, neq, gt, gte, lt, lte, between, is_null and not_null, with numeric values.',
    type: 'object',
    required: ['measures'],
    properties: [
        new OA\Property(property: 'dimensions', type: 'array', description: 'Up to 4 dimension keys to group by; a date dimension takes a granularity (`date:month`, one of day, week, month, quarter, year)', items: new OA\Items(type: 'string'), example: ['customer', 'date:month']),
        new OA\Property(property: 'measures', type: 'array', description: 'Between 1 and 8 measure keys', items: new OA\Items(type: 'string'), example: ['net_amount', 'document_count']),
        new OA\Property(property: 'filters', type: 'array', description: 'Up to 20 conditions on dimensions or measures. Operators by type: string eq, neq, in, not_in, contains, starts_with, is_null, not_null; number and date eq, neq (number), gt, gte, lt, lte, between, in, not_in (number), is_null, not_null; boolean eq, neq. `in` takes a list, `between` a pair.', items: new OA\Items(type: 'object', required: ['field', 'operator'], properties: [new OA\Property(property: 'field', type: 'string', example: 'customer_country'), new OA\Property(property: 'operator', type: 'string', example: 'eq'), new OA\Property(property: 'value', example: 'IT')])),
        new OA\Property(property: 'sort', type: 'array', description: 'Up to 4 chosen dimensions or measures. Without it the first measure is sorted descending.', items: new OA\Items(type: 'object', required: ['field'], properties: [new OA\Property(property: 'field', type: 'string', example: 'net_amount'), new OA\Property(property: 'direction', type: 'string', enum: ['asc', 'desc'], example: 'desc')])),
        new OA\Property(property: 'limit', type: 'integer', nullable: true, description: 'Top-N: only the first N rows of the sorted result', example: 10),
        new OA\Property(property: 'period', type: 'object', nullable: true, description: 'Applies to the date dimension of the dataset (datasets that describe today have none). Either a preset that moves with the calendar of the company, or fixed dates (at most 1830 days).', properties: [new OA\Property(property: 'preset', type: 'string', enum: ['month_to_date', 'last_month', 'quarter_to_date', 'year_to_date', 'last_30_days', 'last_12_months']), new OA\Property(property: 'from', type: 'string', format: 'date'), new OA\Property(property: 'to', type: 'string', format: 'date')]),
        new OA\Property(property: 'compare', type: 'string', enum: ['none', 'previous_period', 'previous_year'], default: 'none', description: 'Adds `<measure>__previous`, `__change` and `__change_percent` to every row. When grouping by a date, each previous bucket is moved forward by a year (previous_year) or by the length of the period (previous_period) and matched to the current bucket of the same granularity. Filters on measures select the current rows only.'),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsSemanticDataset',
    type: 'object',
    properties: [
        new OA\Property(property: 'key', type: 'string', example: 'sales'),
        new OA\Property(property: 'label', type: 'string', example: 'Sales'),
        new OA\Property(property: 'description', type: 'string'),
        new OA\Property(property: 'module', type: 'string', description: 'Module whose read permission the dataset needs', example: 'invoicing'),
        new OA\Property(property: 'supports_period', type: 'boolean'),
        new OA\Property(property: 'period_dimension', type: 'string', nullable: true, example: 'date'),
        new OA\Property(property: 'dimensions', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'type', type: 'string', enum: ['string', 'number', 'date', 'boolean']), new OA\Property(property: 'operators', type: 'array', items: new OA\Items(type: 'string')), new OA\Property(property: 'granularities', type: 'array', items: new OA\Items(type: 'string'))])),
        new OA\Property(property: 'measures', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'unit', type: 'string', enum: ['currency', 'count', 'quantity', 'percent'])])),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsReportResult',
    description: 'One page of a report. Measures are decimal strings (counts are integers); null where there is no value.',
    type: 'object',
    properties: [
        new OA\Property(property: 'columns', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'key', type: 'string'), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'kind', type: 'string', enum: ['dimension', 'measure', 'comparison'])])),
        new OA\Property(property: 'rows', type: 'array', items: new OA\Items(type: 'object', additionalProperties: true)),
        new OA\Property(property: 'meta', type: 'object', properties: [
            new OA\Property(property: 'dataset', type: 'string'),
            new OA\Property(property: 'page', type: 'integer'),
            new OA\Property(property: 'per_page', type: 'integer'),
            new OA\Property(property: 'total', type: 'integer', description: 'Rows of the report, capped by the top-N and the row limit'),
            new OA\Property(property: 'pages', type: 'integer'),
            new OA\Property(property: 'truncated', type: 'boolean', description: 'True when the row limit cut the report: narrow it'),
            new OA\Property(property: 'row_limit', type: 'integer'),
            new OA\Property(property: 'period', type: 'object', nullable: true, additionalProperties: true),
            new OA\Property(property: 'comparison_period', type: 'object', nullable: true, additionalProperties: true),
        ]),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsDesignerRequest',
    type: 'object',
    required: ['dataset', 'spec'],
    properties: [
        new OA\Property(property: 'dataset', type: 'string', example: 'sales'),
        new OA\Property(property: 'spec', ref: '#/components/schemas/AnalyticsReportSpec'),
        new OA\Property(property: 'page', type: 'integer', minimum: 1),
        new OA\Property(property: 'per_page', type: 'integer', minimum: 1, maximum: 500),
        new OA\Property(property: 'format', type: 'string', enum: ['csv', 'excel_csv', 'xlsx'], description: 'Used by the export endpoint only'),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsDesignedReport',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'dataset', type: 'string'),
        new OA\Property(property: 'spec', ref: '#/components/schemas/AnalyticsReportSpec'),
        new OA\Property(property: 'is_shared', type: 'boolean', description: 'Visible to the whole company'),
        new OA\Property(property: 'shared_with', type: 'array', description: 'Users it is shared with (only returned to the owner)', items: new OA\Items(type: 'string', format: 'uuid')),
        new OA\Property(property: 'is_owner', type: 'boolean'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsSaveDesignedReportRequest',
    type: 'object',
    required: ['name', 'dataset', 'spec'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 150),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'dataset', type: 'string', example: 'sales'),
        new OA\Property(property: 'spec', ref: '#/components/schemas/AnalyticsReportSpec'),
        new OA\Property(property: 'is_shared', type: 'boolean', default: false),
        new OA\Property(property: 'shared_with', type: 'array', description: 'Members of the company to share with (up to 50)', items: new OA\Items(type: 'string', format: 'uuid')),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsExportSchedule',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'user_id', type: 'string', format: 'uuid', description: 'Identity the export runs as'),
        new OA\Property(property: 'designed_report_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'frequency', type: 'string', enum: ['daily', 'weekly', 'monthly']),
        new OA\Property(property: 'day_of_week', type: 'integer', nullable: true, description: '1 Monday to 7 Sunday'),
        new OA\Property(property: 'day_of_month', type: 'integer', nullable: true, description: '1 to 28'),
        new OA\Property(property: 'format', type: 'string', enum: ['csv', 'excel_csv', 'xlsx']),
        new OA\Property(property: 'delivery', type: 'string', enum: ['email', 'documents', 'both']),
        new OA\Property(property: 'recipients', type: 'array', items: new OA\Items(type: 'string', format: 'email')),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'next_run_on', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'last_run_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'last_status', type: 'string', nullable: true, enum: ['ok', 'failed']),
        new OA\Property(property: 'last_error', type: 'string', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsSaveExportScheduleRequest',
    type: 'object',
    required: ['designed_report_id', 'frequency', 'delivery'],
    properties: [
        new OA\Property(property: 'designed_report_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'frequency', type: 'string', enum: ['daily', 'weekly', 'monthly']),
        new OA\Property(property: 'day_of_week', type: 'integer', minimum: 1, maximum: 7, description: 'Required for weekly'),
        new OA\Property(property: 'day_of_month', type: 'integer', minimum: 1, maximum: 28, description: 'Required for monthly'),
        new OA\Property(property: 'format', type: 'string', enum: ['csv', 'excel_csv', 'xlsx'], default: 'csv'),
        new OA\Property(property: 'delivery', type: 'string', enum: ['email', 'documents', 'both'], description: '`email` sends a signed link to the file, `documents` stores it in the Documents module (needs write access to it), `both` does both'),
        new OA\Property(property: 'recipients', type: 'array', description: 'Required for email delivery; members of the company unless the installation allows external recipients', items: new OA\Items(type: 'string', format: 'email')),
        new OA\Property(property: 'is_active', type: 'boolean', default: true),
    ]
)]
#[OA\Schema(
    schema: 'AnalyticsKpiSnapshot',
    type: 'object',
    properties: [
        new OA\Property(property: 'kpi', type: 'string', example: 'sales'),
        new OA\Property(property: 'snapshot_date', type: 'string', format: 'date'),
        new OA\Property(property: 'period_from', type: 'string', format: 'date'),
        new OA\Property(property: 'period_to', type: 'string', format: 'date'),
        new OA\Property(property: 'value', type: 'string', nullable: true),
        new OA\Property(property: 'details', type: 'object', nullable: true, additionalProperties: true),
        new OA\Property(property: 'computed_at', type: 'string', format: 'date-time'),
    ]
)] final class BiSchemas {}
