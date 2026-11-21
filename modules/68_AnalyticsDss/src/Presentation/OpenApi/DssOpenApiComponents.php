<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Shared parameters of the executive DSS endpoints: the global strategic filter. They live apart from the schemas, as
 * swagger-php drops every schema of a class that also declares class-level parameters.
 */
#[OA\Parameter(parameter: 'DssLegalEntityIds', name: 'legal_entity_ids[]', description: 'Legal entities (companies) to include; all when omitted', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid')))]
#[OA\Parameter(parameter: 'DssCostCenterIds', name: 'cost_center_ids[]', description: 'Cost centers to include; all when omitted', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid')))]
#[OA\Parameter(parameter: 'DssCurrency', name: 'currency', description: 'Reporting currency of every amount', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['CHF', 'EUR'], default: 'CHF'))]
#[OA\Parameter(parameter: 'DssPeriodPreset', name: 'period_preset', description: 'Period: month, quarter or year to date, last 12 months, or a custom range', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['MTD', 'QTD', 'YTD', 'L12M', 'CUSTOM'], default: 'YTD'))]
#[OA\Parameter(parameter: 'DssDateFrom', name: 'date_from', description: 'Start of the CUSTOM period', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'DssDateTo', name: 'date_to', description: 'End of the CUSTOM period', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
#[OA\Parameter(parameter: 'DssComparisonBaseline', name: 'comparison_baseline', description: 'What the period is compared with', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['PRIOR_PERIOD', 'PRIOR_YEAR', 'BUDGET'], default: 'PRIOR_YEAR'))]
#[OA\Parameter(parameter: 'DssAsOf', name: 'as_of', description: 'Day the presets are resolved on (today when omitted)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
final class DssOpenApiComponents {}
