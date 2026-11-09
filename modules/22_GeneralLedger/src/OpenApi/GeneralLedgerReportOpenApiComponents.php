<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Query parameters shared by the ledger reports: date range, comparison range and dimension filters.
 */
#[OA\Parameter(parameter: 'GeneralLedgerReportFrom', name: 'from', description: 'First posting date of the range', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2027-01-01'))]
#[OA\Parameter(parameter: 'GeneralLedgerReportTo', name: 'to', description: 'Last posting date of the range', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2027-03-31'))]
#[OA\Parameter(parameter: 'GeneralLedgerReportCompareFrom', name: 'compare_from', description: 'First date of the comparison range (with compare_to)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-01-01'))]
#[OA\Parameter(parameter: 'GeneralLedgerReportCompareTo', name: 'compare_to', description: 'Last date of the comparison range (with compare_from)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-31'))]
#[OA\Parameter(parameter: 'GeneralLedgerReportCostCenter', name: 'cost_center_id', description: 'Only lines of this cost centre', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'GeneralLedgerReportProfitCenter', name: 'profit_center_id', description: 'Only lines of this profit centre', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
#[OA\Parameter(parameter: 'GeneralLedgerReportProject', name: 'project_id', description: 'Only lines of this project', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))]
final class GeneralLedgerReportOpenApiComponents {}
