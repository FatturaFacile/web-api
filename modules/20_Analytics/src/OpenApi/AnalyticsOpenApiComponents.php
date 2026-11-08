<?php

declare(strict_types=1);

namespace Modules\Analytics\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters and responses shared by the reporting endpoints of the Analytics module.
 */
#[OA\Tag(name: 'Analytics', description: 'KPI endpoints over posted data, saved report definitions and CSV export')]
#[OA\Parameter(parameter: 'AnaPeriodFrom', name: 'from', description: 'First day of the period. Defaults to the first day of the current month. A period cannot exceed 366 days.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2027-01-01'))]
#[OA\Parameter(parameter: 'AnaPeriodTo', name: 'to', description: 'Last day of the period. Defaults to today.', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2027-03-31'))]
#[OA\Parameter(parameter: 'AnaCompare', name: 'compare', description: '`previous_period` compares with the period of the same length right before, `previous_year` with the same dates one year earlier. Indicators that describe today (aging, stock, projects) are never compared.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['none', 'previous_period', 'previous_year'], default: 'none'))]
#[OA\Parameter(parameter: 'AnaDefinitionId', name: 'id', description: 'Identifier of the saved report definition', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Response(response: 'AnaUnauthenticated', description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'AnaForbidden', description: 'The role of the user in the company cannot use the analytics module (or change this definition)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'AnaNotFound', description: 'Not found in the active company, or a private definition of another user', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))]
#[OA\Response(response: 'AnaUnprocessable', description: 'Invalid period, indicator or definition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))]
final class AnalyticsOpenApiComponents {}
