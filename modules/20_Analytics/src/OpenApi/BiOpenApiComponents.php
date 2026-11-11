<?php

declare(strict_types=1);

namespace Modules\Analytics\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Parameters of the self-service reporting endpoints (semantic layer, report designer, exports, scheduled
 * exports and KPI snapshots) of the Analytics module.
 */
#[OA\Tag(name: 'Analytics BI', description: 'Governed semantic layer, report designer, exports, scheduled exports and daily KPI snapshots. No SQL is accepted: reports are built from the keys of a catalogue. Datasets follow the permissions of their module.')]
#[OA\Parameter(parameter: 'AnaDatasetKey', name: 'dataset', description: 'Key of a dataset of the catalogue (sales, purchases, inventory, ledger, projects, hr_headcount)', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'sales'))]
#[OA\Parameter(parameter: 'AnaDesignedReportId', name: 'id', description: 'Identifier of the designed report', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'AnaScheduleId', name: 'id', description: 'Identifier of the scheduled export', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'AnaPage', name: 'page', description: 'Page number, from 1', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
#[OA\Parameter(parameter: 'AnaPerPage', name: 'per_page', description: 'Rows per page, at most 500. A report never returns more than the row limit (10000 by default) in total.', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500, default: 50))]
#[OA\Parameter(parameter: 'AnaExportFormat', name: 'format', description: '`csv`: comma separated UTF-8. `excel_csv`: semicolons and byte order mark, opens correctly in Excel. `xlsx`: a real Excel workbook (typed cells, bold frozen header, a second sheet `Parameters` with dataset, filters, period and generation time). Same row limit for every format.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['csv', 'excel_csv', 'xlsx'], default: 'csv'))]
final class BiOpenApiComponents {}
