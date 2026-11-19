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
#[OA\Parameter(parameter: 'AnaExportFormat', name: 'format', description: '`csv`: comma separated UTF-8. `excel_csv`: semicolons and byte order mark, opens correctly in Excel. `xlsx`: a real Excel workbook (typed cells, bold frozen header, a second sheet `Parameters` with dataset, filters, period and generation time). `pdf`: an executive document with the tenant name, summary figures, the chart snapshot of a pivot and a footer with the filters and the generation time (at most 2000 rows). `json`: metadata, columns, rows and totals. Same row limit for every other format.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['csv', 'excel_csv', 'xlsx', 'pdf', 'json'], default: 'csv'))]
#[OA\Parameter(parameter: 'AnaExportLocale', name: 'locale', description: 'Regional locale of the file. `it-CH`, `de-CH`, `fr-CH`: apostrophe thousands and dot decimal (1\'234.50), dates dd.MM.yyyy, `;` in CSV. `it-IT`: 1.234,50, dd/MM/yyyy, `;`. `en`: 1,234.50, yyyy-MM-dd, `,`. Headers are translated into the language of the locale. Without it the file keeps the stable keys and raw values.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en']))]
final class BiOpenApiComponents {}
