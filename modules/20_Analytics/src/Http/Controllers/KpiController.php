<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Analytics\Http\Requests\KpiQueryRequest;
use Modules\Analytics\Services\KpiCatalog;
use Modules\Analytics\Services\KpiCsvExporter;
use Modules\Analytics\Services\KpiSnapshotService;
use OpenApi\Attributes as OA;

/**
 * KPI endpoints over posted data: sales, purchases, margin, aging, stock, project margin and headcount.
 */
final class KpiController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly KpiCatalog $catalog, private readonly KpiCsvExporter $exporter, private readonly KpiSnapshotService $snapshots) {}

    /**
     * All indicators in one call.
     */
    #[OA\Get(
        path: '/api/v1/analytics/kpis',
        operationId: 'listAnalyticsKpis',
        description: 'The indicators of the reporting layer for a period, optionally compared with the previous period or the same dates one year earlier. Every figure comes from posted data (posted invoices, supplier invoices posted to the ledger, the statutory ledger, stock quants, project reports, employee dates). `value` is null where there is no data, never zero. An indicator drawn from a module the caller cannot read is returned with `available: false`. Aging, stock value and project margin describe today and are not compared.',
        summary: 'List KPIs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodFrom'),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodTo'),
            new OA\Parameter(ref: '#/components/parameters/AnaCompare'),
            new OA\Parameter(ref: '#/components/parameters/AnaKpiSource'),
            new OA\Parameter(name: 'kpis[]', description: 'Indicators to include (default: all)', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', enum: ['sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value', 'project-margin', 'hr-headcount']))),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The KPI report', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsKpiReport')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function index(KpiQueryRequest $request): JsonResponse
    {
        if ($request->wantsSnapshots()) {
            return $this->successResponse($this->snapshots->report($request->kpiKeys(), $request->period(), $request->user()), 'KPIs retrieved from the daily snapshots');
        }

        return $this->successResponse($this->catalog->report($request->kpiKeys(), $request->period(), $request->user()), 'KPIs retrieved');
    }

    /**
     * One indicator.
     */
    #[OA\Get(
        path: '/api/v1/analytics/kpis/{kpi}',
        operationId: 'showAnalyticsKpi',
        description: 'One indicator with its period and comparison. Use `kpis/{kpi}/export` for the table behind it.',
        summary: 'Show a KPI',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'kpi', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value', 'project-margin', 'hr-headcount'])),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodFrom'),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodTo'),
            new OA\Parameter(ref: '#/components/parameters/AnaCompare'),
            new OA\Parameter(ref: '#/components/parameters/AnaKpiSource'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The KPI', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsKpiResult')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(response: 404, description: 'Unknown indicator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function show(KpiQueryRequest $request, string $kpi): JsonResponse
    {
        abort_unless(in_array($kpi, KpiCatalog::keys(), true), 404, 'Unknown indicator.');

        if ($request->wantsSnapshots()) {
            return $this->successResponse($this->snapshots->report([$kpi], $request->period(), $request->user())['kpis'][0], 'KPI retrieved from the daily snapshots');
        }

        return $this->successResponse($this->catalog->report([$kpi], $request->period(), $request->user())['kpis'][0], 'KPI retrieved');
    }

    /**
     * Summary CSV.
     */
    #[OA\Get(
        path: '/api/v1/analytics/kpis-export',
        operationId: 'exportAnalyticsKpis',
        description: 'Excel-compatible CSV (semicolons, UTF-8 BOM) with one row per indicator: value, comparison and change. Text cells are neutralised against spreadsheet formula injection.',
        summary: 'Export KPIs to CSV',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodFrom'),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodTo'),
            new OA\Parameter(ref: '#/components/parameters/AnaCompare'),
            new OA\Parameter(name: 'kpis[]', description: 'Indicators to include (default: all)', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function exportSummary(KpiQueryRequest $request): Response
    {
        $report = $this->catalog->report($request->kpiKeys(), $request->period(), $request->user());

        return $this->csv($this->exporter->summary($report), 'kpis');
    }

    /**
     * Detail CSV.
     */
    #[OA\Get(
        path: '/api/v1/analytics/kpis/{kpi}/export',
        operationId: 'exportAnalyticsKpiDetail',
        description: 'CSV of the table behind one indicator (by customer, supplier, account, item, project or department), capped at 5000 rows: when the cap is reached the response carries `X-Export-Truncated: true`. Text cells are neutralised against spreadsheet formula injection.',
        summary: 'Export the detail of a KPI to CSV',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'kpi', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value', 'project-margin', 'hr-headcount'])),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodFrom'),
            new OA\Parameter(ref: '#/components/parameters/AnaPeriodTo'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(response: 403, description: 'The caller cannot read the module of this indicator, or cannot use analytics', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown indicator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function exportDetail(KpiQueryRequest $request, string $kpi): Response
    {
        abort_unless(in_array($kpi, KpiCatalog::keys(), true), 404, 'Unknown indicator.');
        abort_unless($this->catalog->canRead($this->catalog->calculator($kpi), $request->user()), 403, 'You cannot read the data of this indicator.');

        $rows = $this->catalog->rows($kpi, $request->period(), $request->user());

        return $this->csv($this->exporter->detail($rows), "{$kpi}-detail", $this->exporter->isTruncated($rows));
    }

    private function csv(string $content, string $name, bool $truncated = false): Response
    {
        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}.csv\"",
            'X-Export-Truncated' => $truncated ? 'true' : 'false',
        ]);
    }
}
